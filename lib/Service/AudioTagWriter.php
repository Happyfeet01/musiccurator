<?php

declare(strict_types=1);

namespace OCA\MusicCurator\Service;

use OCP\Files\File;
use RuntimeException;
use Throwable;

class AudioTagWriter {
	/**
	 * Rewrite selected MP3 metadata fields with ffmpeg while copying the audio
	 * stream losslessly. Existing metadata that is not explicitly overridden is
	 * preserved by ffmpeg's metadata mapping.
	 *
	 * @param array<string, string> $metadata
	 * @return array{bytes: int, fields: list<string>}
	 */
	public function writeMp3(File $file, array $metadata): array {
		$extension = strtolower(pathinfo($file->getName(), PATHINFO_EXTENSION));
		if ($extension !== 'mp3') {
			throw new RuntimeException('Experimental tag writing currently supports MP3 files only.');
		}
		if ($metadata === []) {
			throw new RuntimeException('No metadata fields were selected for writing.');
		}
		if (!function_exists('proc_open')) {
			throw new RuntimeException('PHP proc_open is unavailable, so ffmpeg cannot be started.');
		}

		$inputPath = $this->temporaryPath('musiccurator-in-', '.mp3');
		$outputPath = $this->temporaryPath('musiccurator-out-', '.mp3', false);

		try {
			$this->copyNextcloudFileToPath($file, $inputPath);
			$this->runFfmpeg($inputPath, $outputPath, $metadata);

			$bytes = is_file($outputPath) ? (int)filesize($outputPath) : 0;
			if ($bytes <= 0) {
				throw new RuntimeException('ffmpeg produced an empty output file.');
			}

			try {
				$this->replaceNextcloudFileFromPath($file, $outputPath);
			} catch (Throwable $writeError) {
				// The complete original file is still available in the temporary input
				// file. Restore it before surfacing the failure to the caller.
				try {
					$this->replaceNextcloudFileFromPath($file, $inputPath);
				} catch (Throwable) {
					throw new RuntimeException('Metadata write failed and restoring the original file also failed: ' . $writeError->getMessage(), 0, $writeError);
				}
				throw $writeError;
			}

			return [
				'bytes' => $bytes,
				'fields' => array_values(array_keys($metadata)),
			];
		} finally {
			@unlink($inputPath);
			@unlink($outputPath);
		}
	}

	/** @param array<string, string> $metadata */
	private function runFfmpeg(string $inputPath, string $outputPath, array $metadata): void {
		$command = [
			'ffmpeg',
			'-hide_banner',
			'-loglevel', 'error',
			'-y',
			'-i', $inputPath,
			'-map', '0',
			'-c', 'copy',
			'-map_metadata', '0',
		];

		$tagNames = [
			'title' => 'title',
			'artist' => 'artist',
			'album' => 'album',
			'albumArtist' => 'album_artist',
			'track' => 'track',
			'year' => 'date',
			'genre' => 'genre',
		];
		foreach ($metadata as $field => $value) {
			if (!isset($tagNames[$field])) {
				continue;
			}
			$command[] = '-metadata';
			$command[] = $tagNames[$field] . '=' . trim($value);
		}
		$command[] = $outputPath;

		$descriptors = [
			0 => ['pipe', 'r'],
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w'],
		];
		$process = @proc_open($command, $descriptors, $pipes);
		if (!is_resource($process)) {
			throw new RuntimeException('Could not start ffmpeg. Make sure ffmpeg is installed and available in the PHP-FPM PATH.');
		}

		fclose($pipes[0]);
		$stdout = stream_get_contents($pipes[1]);
		$stderr = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$status = proc_close($process);

		if ($status !== 0) {
			$detail = trim((string)$stderr);
			if ($detail === '') {
				$detail = trim((string)$stdout);
			}
			throw new RuntimeException('ffmpeg metadata write failed' . ($detail !== '' ? ': ' . mb_substr($detail, 0, 800) : '.'));
		}
	}

	private function copyNextcloudFileToPath(File $file, string $targetPath): void {
		$source = $file->fopen('r');
		if (!is_resource($source)) {
			throw new RuntimeException('Could not open the Nextcloud file for reading.');
		}
		$target = fopen($targetPath, 'wb');
		if (!is_resource($target)) {
			fclose($source);
			throw new RuntimeException('Could not create a temporary input file.');
		}

		try {
			if (stream_copy_to_stream($source, $target) === false) {
				throw new RuntimeException('Could not copy the audio file to temporary storage.');
			}
		} finally {
			fclose($source);
			fclose($target);
		}
	}

	private function replaceNextcloudFileFromPath(File $file, string $sourcePath): void {
		$source = fopen($sourcePath, 'rb');
		if (!is_resource($source)) {
			throw new RuntimeException('Could not open the generated audio file.');
		}
		$target = $file->fopen('w');
		if (!is_resource($target)) {
			fclose($source);
			throw new RuntimeException('Could not open the Nextcloud file for writing.');
		}

		try {
			if (stream_copy_to_stream($source, $target) === false) {
				throw new RuntimeException('Could not write the generated audio file back to Nextcloud.');
			}
		} finally {
			fclose($source);
			fclose($target);
		}
	}

	private function temporaryPath(string $prefix, string $suffix, bool $create = true): string {
		$base = tempnam(sys_get_temp_dir(), $prefix);
		if ($base === false) {
			throw new RuntimeException('Could not allocate temporary storage.');
		}
		$path = $base . $suffix;
		if ($create) {
			if (!@rename($base, $path)) {
				@unlink($base);
				throw new RuntimeException('Could not prepare temporary storage.');
			}
		} else {
			@unlink($base);
		}
		return $path;
	}
}
