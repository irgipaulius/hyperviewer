<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Service;

class FfmpegRunner {
	private ?array $lastProgress = null;
	private int $lastProgressAt = 0;
	private string $lastStderr = '';

	public function __construct(
		private ConfigService $config,
	) {
	}

	/**
	 * @param list<string> $args ffmpeg arguments after binary
	 * @param callable(int $progress): void|null $onProgress
	 */
	public function run(array $args, ?float $durationSeconds = null, ?callable $onProgress = null, ?callable $shouldCancel = null): int {
		$binary = $this->config->getString(ConfigService::KEY_FFMPEG);
		if ($binary === '' || !is_executable($binary)) {
			throw new \RuntimeException('ffmpeg binary not configured');
		}
		$cmd = $this->wrapPriority(array_merge([$binary, '-nostdin', '-hide_banner', '-loglevel', 'error'], $args));
		$descriptors = [
			0 => ['pipe', 'r'],
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w'],
		];
		$proc = proc_open($cmd, $descriptors, $pipes);
		if (!is_resource($proc)) {
			throw new \RuntimeException('Failed to start ffmpeg');
		}
		fclose($pipes[0]);
		stream_set_blocking($pipes[1], false);
		stream_set_blocking($pipes[2], false);
		$start = time();
		$stallSeconds = $this->config->getInt(ConfigService::KEY_STALL_SECONDS);
		$timeoutMult = $this->config->getInt(ConfigService::KEY_ENCODE_TIMEOUT_MULT);
		$maxWall = $durationSeconds !== null
			? (int)($durationSeconds * $timeoutMult + 600)
			: 7200;
		$stdoutBuf = '';
		$this->lastStderr = '';
		while (true) {
			if ($shouldCancel !== null && $shouldCancel()) {
				$this->terminate($proc);
				return 130;
			}
			$now = time();
			if ($now - $start > $maxWall) {
				$this->terminate($proc);
				throw new \RuntimeException('ffmpeg wall clock timeout');
			}
			if ($this->lastProgressAt > 0 && ($now - $this->lastProgressAt) > $stallSeconds) {
				$this->terminate($proc);
				throw new \RuntimeException('ffmpeg stalled');
			}
			$chunk = fread($pipes[1], 8192);
			if ($chunk !== false && $chunk !== '') {
				$stdoutBuf .= $chunk;
				while (($pos = strpos($stdoutBuf, "\n")) !== false) {
					$line = substr($stdoutBuf, 0, $pos);
					$stdoutBuf = substr($stdoutBuf, $pos + 1);
					$this->parseProgressLine($line, $durationSeconds, $onProgress);
				}
			}
			$this->readStderr($pipes[2]);
			$status = proc_get_status($proc);
			if (!$status['running']) {
				break;
			}
			usleep(200000);
		}
		stream_set_blocking($pipes[2], true);
		$this->readStderr($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		return proc_close($proc);
	}

	/** @param resource $pipe */
	private function readStderr($pipe): void {
		$err = stream_get_contents($pipe);
		if ($err !== false && $err !== '') {
			$this->lastStderr = substr($this->lastStderr . $err, -4000);
		}
	}

	public function runFfprobe(array $args): string {
		$binary = $this->config->getString(ConfigService::KEY_FFPROBE);
		if ($binary === '' || !is_executable($binary)) {
			throw new \RuntimeException('ffprobe binary not configured');
		}
		$cmd = array_merge([$binary, '-hide_banner', '-loglevel', 'error'], $args);
		$descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
		$proc = proc_open($cmd, $descriptors, $pipes);
		if (!is_resource($proc)) {
			throw new \RuntimeException('Failed to start ffprobe');
		}
		fclose($pipes[0]);
		$out = stream_get_contents($pipes[1]);
		$err = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$code = proc_close($proc);
		if ($code !== 0) {
			throw new \RuntimeException(trim($err ?: $out ?: 'ffprobe failed'));
		}
		return $out ?: '';
	}

	public function extractFrame(string $input, string $output, float $timestamp, int $timeoutSeconds): void {
		$threads = 1;
		$args = [
			'-threads', (string)$threads,
			'-ss', (string)$timestamp,
			'-i', $input,
			'-frames:v', '1',
			'-q:v', '2',
			'-y', $output,
		];
		$start = time();
		$code = $this->run($args, null, null, function () use ($start, $timeoutSeconds): bool {
			return (time() - $start) > $timeoutSeconds;
		});
		if ($code !== 0 || !is_file($output)) {
			throw new \RuntimeException('Frame extraction failed');
		}
	}

	/** @return list<string> */
	private function wrapPriority(array $cmd): array {
		$mode = $this->config->getString(ConfigService::KEY_CPU_PRIORITY);
		if ($mode === 'none') {
			return $cmd;
		}
		if ($mode === 'auto') {
			if (PHP_OS_FAMILY === 'FreeBSD' && self::idprioUsable()) {
				return array_merge(['/usr/sbin/idprio', '31'], $cmd);
			}
			if (PHP_OS_FAMILY === 'FreeBSD' && is_executable('/usr/bin/nice')) {
				// idprio needs privileges a jailed www user lacks (RTP_SET: Operation not permitted).
				return array_merge(['/usr/bin/nice', '-n', '19'], $cmd);
			}
			if (PHP_OS_FAMILY === 'Linux' && is_executable('/usr/bin/nice')) {
				$wrapped = ['/usr/bin/nice', '-n', '19'];
				if (is_executable('/usr/bin/ionice')) {
					$wrapped = array_merge(['/usr/bin/ionice', '-c3'], $wrapped);
				}
				return array_merge($wrapped, $cmd);
			}
		}
		return $cmd;
	}

	private static ?bool $idprioUsable = null;

	/** idprio exists but fails for unprivileged users in jails; probe once per process. */
	public static function idprioUsable(?string $binary = '/usr/sbin/idprio'): bool {
		if ($binary !== '/usr/sbin/idprio') {
			return self::probeIdprio($binary);
		}
		if (self::$idprioUsable === null) {
			self::$idprioUsable = self::probeIdprio($binary);
		}
		return self::$idprioUsable;
	}

	private static function probeIdprio(string $binary): bool {
		if (!is_executable($binary)) {
			return false;
		}
		$true = is_executable('/usr/bin/true') ? '/usr/bin/true' : '/bin/true';
		$proc = proc_open([$binary, '31', $true], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
		if (!is_resource($proc)) {
			return false;
		}
		foreach ($pipes as $p) {
			stream_get_contents($p);
			fclose($p);
		}
		return proc_close($proc) === 0;
	}

	public function getLastStderr(): string {
		return trim($this->lastStderr);
	}

	private function parseProgressLine(string $line, ?float $durationSeconds, ?callable $onProgress): void {
		if (!str_contains($line, 'out_time_us=') && !str_contains($line, 'out_time_ms=')) {
			return;
		}
		if ($durationSeconds === null || $durationSeconds <= 0) {
			return;
		}
		if (preg_match('/out_time_us=(\d+)/', $line, $m)) {
			$us = (int)$m[1];
			$progress = (int)min(99, round(($us / 1_000_000) / $durationSeconds * 100));
			$this->lastProgressAt = time();
			if ($onProgress !== null) {
				$onProgress($progress);
			}
		}
	}

	private function terminate($proc): void {
		$status = proc_get_status($proc);
		if ($status['running'] && $status['pid'] > 0) {
			posix_kill($status['pid'], SIGTERM);
			usleep(10_000_000);
			if (proc_get_status($proc)['running']) {
				posix_kill($status['pid'], SIGKILL);
			}
		}
		proc_terminate($proc);
	}
}
