<?php
declare(strict_types=1);

/** Deterministic title feature extraction shared by training and scoring. */
final class InterestFeatureExtractor {
	private const MAX_TITLE_CODEPOINTS = 512;
	private const MAX_FEATURES = 128;

	/** @param array<int,string> $sourceGroups */
	public function __construct(private readonly array $sourceGroups = []) {
	}

	public static function normalizeSourceGroup(string $name): string {
		$name = Normalizer::normalize($name, Normalizer::FORM_KC) ?: $name;
		return trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
	}

	public function sourceGroup(int $feedId): string {
		return self::normalizeSourceGroup($this->sourceGroups[$feedId] ?? '');
	}

	/** @return list<string> */
	public function extract(string $title, int $feedId): array {
		$normalized = Normalizer::normalize($title, Normalizer::FORM_KC);
		if (!is_string($normalized)) {
			$normalized = $title;
		}
		$normalized = mb_convert_case($normalized, MB_CASE_FOLD, 'UTF-8');
		$normalized = mb_substr($normalized, 0, self::MAX_TITLE_CODEPOINTS, 'UTF-8');

		$features = [];
		$group = $this->sourceGroup($feedId);
		$this->add($features, $group === '' ? 'feed:' . max(0, $feedId)
			: 'feed:group:' . hash('sha256', mb_strtolower($group, 'UTF-8')));

		$words = [];
		if (preg_match_all('/[\p{L}\p{N}]+/u', $normalized, $matches) > 0) {
			foreach ($matches[0] as $word) {
				$words[] = $word;
				$this->add($features, 'w:' . $word);
			}
		}
		for ($i = 1, $count = count($words); $i < $count; $i++) {
			$this->add($features, 'b:' . $words[$i - 1] . ' ' . $words[$i]);
		}

		if (preg_match_all('/[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}]+/u', $normalized, $cjkMatches) > 0) {
			foreach ($cjkMatches[0] as $run) {
				$characters = preg_split('//u', $run, -1, PREG_SPLIT_NO_EMPTY) ?: [];
				foreach ([2, 3] as $length) {
					for ($i = 0, $count = count($characters) - $length; $i <= $count; $i++) {
						$this->add($features, 'c' . $length . ':' . implode('', array_slice($characters, $i, $length)));
					}
				}
			}
		}

		return array_slice(array_keys($features), 0, self::MAX_FEATURES);
	}

	/** @param array<string,true> $features */
	private function add(array &$features, string $feature): void {
		if (count($features) < self::MAX_FEATURES) {
			$features[$feature] = true;
		}
	}
}
