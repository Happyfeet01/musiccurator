<?php

declare(strict_types=1);

namespace OCA\MusicCurator\Service;

use OCP\Files\File;

class AudioTagReader {
	/**
	 * @return array{title: string, artist: string, album: string, albumArtist: string, track: string, year: string, genre: string, tagged: bool}
	 */
	public function read(File $file): array {
		$empty = [
			'title' => '',
			'artist' => '',
			'album' => '',
			'albumArtist' => '',
			'track' => '',
			'year' => '',
			'genre' => '',
			'tagged' => false,
		];

		$extension = strtolower(pathinfo($file->getName(), PATHINFO_EXTENSION));
		$stream = $file->fopen('r');
		if (!is_resource($stream)) {
			return $empty;
		}

		try {
			$tags = match ($extension) {
				'mp3' => $this->readMp3($stream),
				'flac' => $this->readFlac($stream),
				default => $empty,
			};
		} finally {
			fclose($stream);
		}

		$tags['tagged'] = $tags['title'] !== '' || $tags['artist'] !== '' || $tags['album'] !== '';
		return $tags;
	}

	/**
	 * @param resource $stream
	 * @return array{title: string, artist: string, album: string, albumArtist: string, track: string, year: string, genre: string, tagged: bool}
	 */
	private function readMp3($stream): array {
		$tags = $this->emptyTags();
		$header = fread($stream, 10);
		if (is_string($header) && strlen($header) === 10 && substr($header, 0, 3) === 'ID3') {
			$version = ord($header[3]);
			$tagSize = $this->synchsafeToInt(substr($header, 6, 4));
			$data = fread($stream, min($tagSize, 2 * 1024 * 1024));
			if (is_string($data)) {
				$this->parseId3Frames($data, $version, $tags);
			}
		}

		if ($tags['title'] === '' && $tags['artist'] === '' && $tags['album'] === '') {
			$this->parseId3v1($stream, $tags);
		}

		return $tags;
	}

	/**
	 * @param array{title: string, artist: string, album: string, albumArtist: string, track: string, year: string, genre: string, tagged: bool} $tags
	 */
	private function parseId3Frames(string $data, int $version, array &$tags): void {
		$map = [
			'TIT2' => 'title',
			'TPE1' => 'artist',
			'TALB' => 'album',
			'TPE2' => 'albumArtist',
			'TRCK' => 'track',
			'TYER' => 'year',
			'TDRC' => 'year',
			'TCON' => 'genre',
		];

		$offset = 0;
		$length = strlen($data);
		while ($offset + 10 <= $length) {
			$frameId = substr($data, $offset, 4);
			if (!preg_match('/^[A-Z0-9]{4}$/', $frameId)) {
				break;
			}

			$sizeBytes = substr($data, $offset + 4, 4);
			$frameSize = $version >= 4
				? $this->synchsafeToInt($sizeBytes)
				: (int)(unpack('Nsize', $sizeBytes)['size'] ?? 0);
			$offset += 10;

			if ($frameSize <= 0 || $offset + $frameSize > $length) {
				break;
			}

			if (isset($map[$frameId])) {
				$value = $this->decodeTextFrame(substr($data, $offset, $frameSize));
				if ($value !== '') {
					$key = $map[$frameId];
					if ($key === 'year' && preg_match('/\d{4}/', $value, $match)) {
						$value = $match[0];
					}
					$tags[$key] = $value;
				}
			}

			$offset += $frameSize;
		}
	}

	private function decodeTextFrame(string $frame): string {
		if ($frame === '') {
			return '';
		}

		$encoding = ord($frame[0]);
		$value = substr($frame, 1);
		$value = match ($encoding) {
			0 => mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1'),
			1 => mb_convert_encoding($value, 'UTF-8', 'UTF-16'),
			2 => mb_convert_encoding($value, 'UTF-8', 'UTF-16BE'),
			default => $value,
		};

		return trim(str_replace("\0", '', $value));
	}

	/**
	 * @param resource $stream
	 * @param array{title: string, artist: string, album: string, albumArtist: string, track: string, year: string, genre: string, tagged: bool} $tags
	 */
	private function parseId3v1($stream, array &$tags): void {
		if (fseek($stream, -128, SEEK_END) !== 0) {
			return;
		}

		$data = fread($stream, 128);
		if (!is_string($data) || strlen($data) !== 128 || substr($data, 0, 3) !== 'TAG') {
			return;
		}

		$tags['title'] = $this->decodeLegacy(substr($data, 3, 30));
		$tags['artist'] = $this->decodeLegacy(substr($data, 33, 30));
		$tags['album'] = $this->decodeLegacy(substr($data, 63, 30));
		$tags['year'] = trim(substr($data, 93, 4));
		if (ord($data[125]) === 0 && ord($data[126]) > 0) {
			$tags['track'] = (string)ord($data[126]);
		}
	}

	private function decodeLegacy(string $value): string {
		$value = rtrim($value, "\0 \t\r\n");
		if ($value === '') {
			return '';
		}
		return trim(mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1'));
	}

	/**
	 * @param resource $stream
	 * @return array{title: string, artist: string, album: string, albumArtist: string, track: string, year: string, genre: string, tagged: bool}
	 */
	private function readFlac($stream): array {
		$tags = $this->emptyTags();
		rewind($stream);
		if (fread($stream, 4) !== 'fLaC') {
			return $tags;
		}

		$last = false;
		while (!$last && !feof($stream)) {
			$header = fread($stream, 4);
			if (!is_string($header) || strlen($header) !== 4) {
				break;
			}

			$first = ord($header[0]);
			$last = ($first & 0x80) !== 0;
			$type = $first & 0x7f;
			$length = (ord($header[1]) << 16) | (ord($header[2]) << 8) | ord($header[3]);
			if ($length < 0 || $length > 8 * 1024 * 1024) {
				break;
			}

			$payload = fread($stream, $length);
			if (!is_string($payload) || strlen($payload) !== $length) {
				break;
			}

			if ($type === 4) {
				$this->parseVorbisComment($payload, $tags);
			}
		}

		return $tags;
	}

	/**
	 * @param array{title: string, artist: string, album: string, albumArtist: string, track: string, year: string, genre: string, tagged: bool} $tags
	 */
	private function parseVorbisComment(string $payload, array &$tags): void {
		$offset = 0;
		$vendorLength = $this->readLittleEndianInt($payload, $offset);
		if ($vendorLength === null || $offset + $vendorLength > strlen($payload)) {
			return;
		}
		$offset += $vendorLength;

		$count = $this->readLittleEndianInt($payload, $offset);
		if ($count === null) {
			return;
		}

		$map = [
			'TITLE' => 'title',
			'ARTIST' => 'artist',
			'ALBUM' => 'album',
			'ALBUMARTIST' => 'albumArtist',
			'TRACKNUMBER' => 'track',
			'DATE' => 'year',
			'GENRE' => 'genre',
		];

		for ($i = 0; $i < min($count, 512); ++$i) {
			$length = $this->readLittleEndianInt($payload, $offset);
			if ($length === null || $length < 0 || $offset + $length > strlen($payload)) {
				break;
			}
			$comment = substr($payload, $offset, $length);
			$offset += $length;
			[$key, $value] = array_pad(explode('=', $comment, 2), 2, '');
			$key = strtoupper($key);
			if (isset($map[$key]) && $value !== '') {
				$target = $map[$key];
				if ($target === 'year' && preg_match('/\d{4}/', $value, $match)) {
					$value = $match[0];
				}
				$tags[$target] = trim($value);
			}
		}
	}

	private function readLittleEndianInt(string $data, int &$offset): ?int {
		if ($offset + 4 > strlen($data)) {
			return null;
		}
		$value = (int)(unpack('Vvalue', substr($data, $offset, 4))['value'] ?? 0);
		$offset += 4;
		return $value;
	}

	private function synchsafeToInt(string $bytes): int {
		if (strlen($bytes) !== 4) {
			return 0;
		}
		return ((ord($bytes[0]) & 0x7f) << 21)
			| ((ord($bytes[1]) & 0x7f) << 14)
			| ((ord($bytes[2]) & 0x7f) << 7)
			| (ord($bytes[3]) & 0x7f);
	}

	/**
	 * @return array{title: string, artist: string, album: string, albumArtist: string, track: string, year: string, genre: string, tagged: bool}
	 */
	private function emptyTags(): array {
		return [
			'title' => '',
			'artist' => '',
			'album' => '',
			'albumArtist' => '',
			'track' => '',
			'year' => '',
			'genre' => '',
			'tagged' => false,
		];
	}
}
