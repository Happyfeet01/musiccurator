<?php

declare(strict_types=1);

namespace OCA\MusicCurator\Service;

final class GenreNormalizer {
	/**
	 * Convert provider-specific genre/style/tag labels into a small set of
	 * useful library genres. Provider order is significant: the first useful
	 * label wins.
	 *
	 * @param array<int, mixed> $values
	 */
	public static function normalize(array $values): string {
		foreach (self::flatten($values) as $raw) {
			$value = self::clean($raw);
			if ($value === '' || self::isNoise($value)) {
				continue;
			}

			$genre = self::map($value);
			if ($genre !== '') {
				return $genre;
			}
		}

		return '';
	}

	/** @param array<int, mixed> $values
	 * @return list<string>
	 */
	private static function flatten(array $values): array {
		$out = [];
		foreach ($values as $value) {
			if (is_array($value)) {
				foreach (self::flatten(array_values($value)) as $nested) {
					$out[] = $nested;
				}
				continue;
			}
			if (is_scalar($value)) {
				$out[] = (string)$value;
			}
		}
		return $out;
	}

	private static function clean(string $value): string {
		$value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$value = mb_strtolower(trim($value));
		$value = str_replace(['_', '–', '—'], [' ', '-', '-'], $value);
		return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
	}

	private static function isNoise(string $value): bool {
		return preg_match('/(?:^|\b)(?:seen live|favorite|favourite|favorites|favourites|awesome|love|spotify|lastfm|last fm|under \d+ listeners|male vocalists?|female vocalists?|00s|10s|20s|80s|90s)(?:\b|$)/u', $value) === 1;
	}

	private static function map(string $value): string {
		$rules = [
			'Schlager' => '/\b(?:schlager|volksmusik|german schlager)\b/u',
			'Metal' => '/\b(?:metal|metalcore|deathcore|doom|black metal|death metal|heavy metal|power metal|thrash)\b/u',
			'Punk' => '/\b(?:punk|hardcore punk|pop punk|post-punk)\b/u',
			'Hip-Hop' => '/\b(?:hip[ -]?hop|rap|trap|grime)\b/u',
			'R&B/Soul' => '/\b(?:r&b|rnb|rhythm and blues|soul|neo soul|motown)\b/u',
			'Reggae' => '/\b(?:reggae|ska|dancehall|dub)\b/u',
			'Country' => '/\b(?:country|bluegrass|americana)\b/u',
			'Folk' => '/\b(?:folk|singer-songwriter|celtic|traditional)\b/u',
			'Jazz' => '/\b(?:jazz|bebop|swing|fusion)\b/u',
			'Blues' => '/\b(?:blues)\b/u',
			'Classical' => '/\b(?:classical|opera|orchestral|baroque|romantic era|chamber music)\b/u',
			'Soundtrack' => '/\b(?:soundtrack|film score|score|musical)\b/u',
			'Latin' => '/\b(?:latin|salsa|bachata|merengue|reggaeton|bossa nova|samba)\b/u',
			'World' => '/\b(?:world|afrobeat|afropop|ethnic)\b/u',
			'Funk/Disco' => '/\b(?:funk|disco|boogie)\b/u',
			'Dance' => '/\b(?:dance|eurodance|house|trance|techno|edm|hardstyle|hands up|garage)\b/u',
			'Electronic' => '/\b(?:electronic|electronica|synth[ -]?pop|ambient|new wave|industrial|drum and bass|dnb|dubstep|electro)\b/u',
			'Rock' => '/\b(?:rock|alternative rock|indie rock|grunge|progressive rock|classic rock|hard rock)\b/u',
			'Pop' => '/\b(?:pop|europop|k-pop|j-pop|dream pop|indie pop|teen pop|deutschpop)\b/u',
		];

		foreach ($rules as $genre => $pattern) {
			if (preg_match($pattern, $value) === 1) {
				return $genre;
			}
		}

		return '';
	}
}
