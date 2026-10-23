<?php

declare(strict_types=1);

namespace OCA\HyperViewer\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Nextcloud QueryBuilder sum() does not accept a column alias; use selectAlias().
 */
final class DbAggregate {
	public static function selectSum(IQueryBuilder $qb, string $column, string $alias): void {
		$qb->selectAlias($qb->func()->sum($column), $alias);
	}
}
