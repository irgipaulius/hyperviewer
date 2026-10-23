<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Tests;

use OCA\HyperViewer\Db\DbAggregate;
use PHPUnit\Framework\TestCase;

class DbAggregateTest extends TestCase {
	public function testSelectSumUsesAliasNotSecondSumArgument(): void {
		$qb = new class {
			public ?string $alias = null;
			public ?object $sumFn = null;

			public function func(): object {
				$self = $this;
				return new class($self) {
					public function __construct(private object $qb) {
					}

					public function sum(string $col): object {
						$this->qb->sumFn = (object)['col' => $col];
						return $this->qb->sumFn;
					}
				};
			}

			public function selectAlias(object $fn, string $alias): void {
				$this->sumFn = $fn;
				$this->alias = $alias;
			}
		};

		DbAggregate::selectSum($qb, 'cache_bytes', 'bytes');
		self::assertSame('bytes', $qb->alias);
		self::assertSame('cache_bytes', $qb->sumFn->col ?? null);
	}
}
