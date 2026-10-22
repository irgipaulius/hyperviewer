<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class SettingsOverrideMapper {
	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function get(string $key): ?string {
		$qb = $this->db->getQueryBuilder();
		$qb->select('value')
			->from('hv_settings_override')
			->where($qb->expr()->eq('key', $qb->createNamedParameter($key)));
		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return $row !== false ? (string)$row['value'] : null;
	}

	public function set(string $key, string $value): void {
		if ($this->get($key) === null) {
			$qb = $this->db->getQueryBuilder();
			$qb->insert('hv_settings_override')
				->values([
					'key' => $qb->createNamedParameter($key),
					'value' => $qb->createNamedParameter($value),
					'updated_at' => $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT),
				]);
			$qb->executeStatement();
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->update('hv_settings_override')
			->set('value', $qb->createNamedParameter($value))
			->set('updated_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('key', $qb->createNamedParameter($key)));
		$qb->executeStatement();
	}

	public function delete(string $key): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('hv_settings_override')
			->where($qb->expr()->eq('key', $qb->createNamedParameter($key)));
		$qb->executeStatement();
	}

	/** @return array<string, string> */
	public function getAll(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('key', 'value')
			->from('hv_settings_override');
		$result = $qb->executeQuery();
		$out = [];
		while ($row = $result->fetch()) {
			$out[(string)$row['key']] = (string)$row['value'];
		}
		$result->closeCursor();
		return $out;
	}
}
