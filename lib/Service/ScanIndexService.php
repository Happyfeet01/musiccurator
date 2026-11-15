<?php

declare(strict_types=1);

namespace OCA\MusicCurator\Service;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\File;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Throwable;

class ScanIndexService {
	private const TABLE = 'musiccurator_tracks';

	public function __construct(
		private IDBConnection $db,
		private LoggerInterface $logger,
	) {
	}

	/** @param array<string, mixed> $tags */
	public function refreshTags(string $userId, File $file, string $path, array $tags): void {
		$row = $this->findRow($userId, $file->getId());
		$track = $row !== null ? ($this->snapshotTrack($row) ?? []) : [];
		$track = array_merge($track, $tags, [
			'path' => $path,
			'fileId' => $file->getId(),
			'filename' => $file->getName(),
			'extension' => strtolower(pathinfo($file->getName(), PATHINFO_EXTENSION)),
			'mime' => $file->getMimeType(),
			'size' => (int)$file->getSize(),
			'mtime' => (int)$file->getMtime(),
			'status' => ($tags['title'] ?? '') !== '' && ($tags['artist'] ?? '') !== '' ? 'Metadata' : 'Needs tags',
		]);
		$this->storeTrack($userId, $file->getId(), $path, $file->getEtag(), (int)$file->getSize(), (int)$file->getMtime(), $track, $row);
	}

	/**
	 * @return array<int, array<string, mixed>> keyed by Nextcloud file id
	 */
	public function loadForUser(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

		$result = $qb->executeQuery();
		$rows = [];
		while (($row = $result->fetchAssociative()) !== false) {
			$fileId = (int)$row['file_id'];
			$rows[$fileId] = $row;
		}
		$result->closeCursor();

		return $rows;
	}

	/**
	 * Return the last indexed metadata without touching the filesystem. This is
	 * intentionally a snapshot: a normal scan is still the source of truth for
	 * detecting new, changed or deleted files.
	 *
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>|null
	 */
	public function snapshotTrack(array $row): ?array {
		$metadata = json_decode((string)($row['metadata'] ?? ''), true);
		if (!is_array($metadata)) {
			return null;
		}

		$metadata['path'] = (string)($row['path'] ?? ($metadata['path'] ?? ''));
		$metadata['fileId'] = (int)($row['file_id'] ?? ($metadata['fileId'] ?? 0));
		$metadata['size'] = (int)($row['size'] ?? ($metadata['size'] ?? 0));
		$metadata['mtime'] = (int)($row['mtime'] ?? ($metadata['mtime'] ?? 0));
		$metadata['scanState'] = 'snapshot';
		$metadata['scannedAt'] = (int)($row['scanned_at'] ?? 0);
		$metadata['musicBrainzRecordingId'] = (string)($row['mb_recording_id'] ?? '');
		$metadata['musicBrainzReleaseId'] = (string)($row['mb_release_id'] ?? '');
		$metadata['musicBrainzScore'] = (int)($row['mb_score'] ?? 0);
		$metadata['musicBrainzMatchedAt'] = (int)($row['matched_at'] ?? 0);
		$metadata = array_merge($metadata, $this->reviewState($row));

		return $metadata;
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array{reviewStatus: 'pending'|'done', reviewUpdatedAt: int}
	 */
	public function reviewState(array $row): array {
		return [
			'reviewStatus' => ($row['review_status'] ?? 'pending') === 'done' ? 'done' : 'pending',
			'reviewUpdatedAt' => (int)($row['review_updated'] ?? 0),
		];
	}

	/** @return array{reviewStatus: string, reviewUpdatedAt: int, history: list<array<string, mixed>>} */
	public function reviewProgress(string $userId, int $fileId): array {
		$row = $this->findRow($userId, $fileId);
		if ($row === null) {
			throw new \OutOfBoundsException('Track is not indexed for this user.');
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'action', 'created_at')->from('musiccurator_review')
			->where($qb->expr()->andX(
				$qb->expr()->eq('user_id', $qb->createNamedParameter($userId)),
				$qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)),
			))->orderBy('id', 'DESC')->setMaxResults(50);
		$result = $qb->executeQuery();
		$history = [];
		while (($event = $result->fetchAssociative()) !== false) {
			$history[] = ['id' => (int)$event['id'], 'action' => (string)$event['action'], 'timestamp' => (int)$event['created_at']];
		}
		$result->closeCursor();
		return [...$this->reviewState($row), 'history' => $history];
	}

	/** Record only completed actions, atomically with the review status. Never store tag values or credentials. */
	public function recordAction(string $userId, int $fileId, string $action, ?string $status = null): void {
		if (!in_array($action, ['marked_done', 'reopened', 'metadata_saved', 'metadata_undone', 'file_moved'], true)
			|| ($status !== null && !in_array($status, ['pending', 'done'], true))) {
			throw new \InvalidArgumentException('Invalid review action or status.');
		}
		$this->db->beginTransaction();
		try {
			$row = $this->findRow($userId, $fileId);
			if ($row === null) {
				throw new \OutOfBoundsException('Track is not indexed for this user.');
			}
			$now = time();
			$qb = $this->db->getQueryBuilder();
			$qb->update(self::TABLE)->set('review_updated', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
				->where($qb->expr()->andX(
					$qb->expr()->eq('user_id', $qb->createNamedParameter($userId)),
					$qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)),
				));
			if ($status !== null) {
				$qb->set('review_status', $qb->createNamedParameter($status));
			}
			$qb->executeStatement();
			$qb = $this->db->getQueryBuilder();
			$qb->insert('musiccurator_review')->values([
				'user_id' => $qb->createNamedParameter($userId),
				'file_id' => $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT),
				'action' => $qb->createNamedParameter($action),
				'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
			])->executeStatement();
			$this->db->commit();
		} catch (Throwable $e) {
			$this->db->rollBack();
			$this->logger->error('MusicCurator review history could not be saved', [
				'app' => 'musiccurator', 'user' => $userId, 'file_id' => $fileId, 'action' => $action, 'exception' => $e,
			]);
			throw $e;
		}
		$this->logger->info('MusicCurator review action saved', [
			'app' => 'musiccurator', 'user' => $userId, 'file_id' => $fileId, 'action' => $action, 'review_status' => $status,
		]);
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>|null
	 */
	public function cachedTrack(array $row, string $etag, int $size, int $mtime): ?array {
		if ((string)($row['etag'] ?? '') !== $etag
			|| (int)($row['size'] ?? -1) !== $size
			|| (int)($row['mtime'] ?? -1) !== $mtime) {
			return null;
		}

		$metadata = $this->snapshotTrack($row);
		if ($metadata === null) {
			return null;
		}

		$metadata['scanState'] = 'cached';

		return $metadata;
	}

	/**
	 * @param array<string, mixed> $track
	 * @param array<string, mixed>|null $existingRow
	 */
	public function storeTrack(
		string $userId,
		int $fileId,
		string $path,
		string $etag,
		int $size,
		int $mtime,
		array $track,
		?array $existingRow = null,
	): void {
		$metadata = json_encode($track, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		$now = time();

		// A Nextcloud file can be encountered more than once during one recursive
		// scan (for example through different virtual paths). The in-memory index
		// is a snapshot from the start of the scan, so a row inserted earlier in
		// the same request is not present there. Re-check the unique key before
		// inserting to make repeated scans/id aliases idempotent.
		if ($existingRow === null || !isset($existingRow['id'])) {
			$existingRow = $this->findRow($userId, $fileId);
		}

		if ($existingRow !== null && isset($existingRow['id'])) {
			$qb = $this->db->getQueryBuilder();
			$qb->update(self::TABLE)
				->set('path', $qb->createNamedParameter($path))
				->set('etag', $qb->createNamedParameter($etag))
				->set('size', $qb->createNamedParameter($size, IQueryBuilder::PARAM_INT))
				->set('mtime', $qb->createNamedParameter($mtime, IQueryBuilder::PARAM_INT))
				->set('scanned_at', $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT))
				->set('metadata', $qb->createNamedParameter($metadata))
				->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$existingRow['id'], IQueryBuilder::PARAM_INT)));
			$qb->executeStatement();
			return;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->insert(self::TABLE)
			->values([
				'user_id' => $qb->createNamedParameter($userId),
				'file_id' => $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT),
				'path' => $qb->createNamedParameter($path),
				'etag' => $qb->createNamedParameter($etag),
				'size' => $qb->createNamedParameter($size, IQueryBuilder::PARAM_INT),
				'mtime' => $qb->createNamedParameter($mtime, IQueryBuilder::PARAM_INT),
				'scanned_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_INT),
				'metadata' => $qb->createNamedParameter($metadata),
				'mb_recording_id' => $qb->createNamedParameter(''),
				'mb_release_id' => $qb->createNamedParameter(''),
				'mb_score' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
				'matched_at' => $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT),
			]);
		$qb->executeStatement();
	}

	/** @return array<string, mixed>|null */
	private function findRow(string $userId, int $fileId): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from(self::TABLE)
			->where($qb->expr()->andX(
				$qb->expr()->eq('user_id', $qb->createNamedParameter($userId)),
				$qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)),
			))
			->setMaxResults(1);

		$result = $qb->executeQuery();
		$row = $result->fetchAssociative();
		$result->closeCursor();

		return is_array($row) ? $row : null;
	}

	/** @param array<string, mixed> $existingRow */
	public function updateSeenPath(array $existingRow, string $path): void {
		if (!isset($existingRow['id']) || (string)($existingRow['path'] ?? '') === $path) {
			return;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('path', $qb->createNamedParameter($path))
			->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$existingRow['id'], IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	public function storeMusicBrainzMatch(
		string $userId,
		int $fileId,
		string $recordingId,
		string $releaseId,
		int $score,
	): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('mb_recording_id', $qb->createNamedParameter($recordingId))
			->set('mb_release_id', $qb->createNamedParameter($releaseId))
			->set('mb_score', $qb->createNamedParameter(max(0, min(100, $score)), IQueryBuilder::PARAM_INT))
			->set('matched_at', $qb->createNamedParameter(time(), IQueryBuilder::PARAM_INT))
			->where($qb->expr()->andX(
				$qb->expr()->eq('user_id', $qb->createNamedParameter($userId)),
				$qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)),
			));
		$qb->executeStatement();
	}
}
