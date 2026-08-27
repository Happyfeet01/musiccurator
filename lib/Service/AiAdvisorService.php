<?php

declare(strict_types=1);

namespace OCA\MusicCurator\Service;

use OCP\Http\Client\IClientService;
use OCP\IConfig;
use RuntimeException;

class AiAdvisorService {
	private const DEFAULT_OPENAI_MODEL = 'gpt-5.6-luna';
	private const DEFAULT_MISTRAL_MODEL = 'mistral-small-latest';
	private const DEFAULT_OLLAMA_URL = 'http://127.0.0.1:11434/api';

	public function __construct(
		private IClientService $clientService,
		private IConfig $config,
		private ProviderCredentialsService $credentials,
	) {
	}

	/**
	 * @param list<array<string, mixed>> $tracks
	 * @return array<string, mixed>
	 */
	public function classifyFolder(string $userId, string $path, array $tracks): array {
		$provider = strtolower(trim($this->config->getUserValue($userId, 'musiccurator', 'ai_provider', 'off')));
		if (!in_array($provider, ['openai', 'mistral', 'ollama'], true)) {
			throw new RuntimeException('No AI advisor provider is enabled in your personal settings.');
		}

		$summary = $this->folderSummary($path, $tracks);
		$prompt = $this->classificationPrompt($summary);
		$started = microtime(true);

		$result = match ($provider) {
			'openai' => $this->classifyWithOpenAi($userId, $prompt),
			'mistral' => $this->classifyWithMistral($userId, $prompt),
			'ollama' => $this->classifyWithOllama($userId, $prompt),
		};

		$result = $this->normalizeResult($result);
		$result['provider'] = $provider;
		$result['durationMs'] = (int)round((microtime(true) - $started) * 1000);
		$result['stats'] = $summary['stats'];
		$result['path'] = $path;

		return $result;
	}

	/** @return array<string, mixed> */
	private function classifyWithOpenAi(string $userId, string $prompt): array {
		$apiKey = $this->credentials->get($userId, 'openai_key');
		if ($apiKey === '') {
			throw new RuntimeException('OpenAI is selected but no API key is configured.');
		}
		$model = trim($this->config->getUserValue($userId, 'musiccurator', 'openai_model', self::DEFAULT_OPENAI_MODEL));
		if ($model === '') {
			$model = self::DEFAULT_OPENAI_MODEL;
		}

		$payload = [
			'model' => $model,
			'input' => [
				['role' => 'system', 'content' => $this->systemPrompt()],
				['role' => 'user', 'content' => $prompt],
			],
			'max_output_tokens' => 350,
			'text' => [
				'format' => [
					'type' => 'json_schema',
					'name' => 'musiccurator_folder_classification',
					'strict' => true,
					'schema' => $this->schema(),
				],
			],
		];

		$data = $this->postJson('https://api.openai.com/v1/responses', $payload, [
			'Authorization' => 'Bearer ' . $apiKey,
		]);

		$text = trim((string)($data['output_text'] ?? ''));
		if ($text === '') {
			foreach (($data['output'] ?? []) as $output) {
				if (!is_array($output)) {
					continue;
				}
				foreach (($output['content'] ?? []) as $content) {
					if (is_array($content) && isset($content['text'])) {
						$text = trim((string)$content['text']);
						if ($text !== '') {
							break 2;
						}
					}
				}
			}
		}
		if ($text === '') {
			throw new RuntimeException('OpenAI returned no structured classification text.');
		}

		$result = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
		if (!is_array($result)) {
			throw new RuntimeException('OpenAI returned an invalid classification.');
		}
		$result['model'] = $model;

		return $result;
	}

	/** @return array<string, mixed> */
	private function classifyWithMistral(string $userId, string $prompt): array {
		$apiKey = $this->credentials->get($userId, 'mistral_key');
		if ($apiKey === '') {
			throw new RuntimeException('Mistral is selected but no API key is configured.');
		}
		$model = trim($this->config->getUserValue($userId, 'musiccurator', 'mistral_model', self::DEFAULT_MISTRAL_MODEL));
		if ($model === '') {
			$model = self::DEFAULT_MISTRAL_MODEL;
		}

		$payload = [
			'model' => $model,
			'messages' => [
				['role' => 'system', 'content' => $this->systemPrompt()],
				['role' => 'user', 'content' => $prompt],
			],
			'response_format' => ['type' => 'json_object'],
			'max_tokens' => 350,
			'temperature' => 0.1,
		];

		$data = $this->postJson('https://api.mistral.ai/v1/chat/completions', $payload, [
			'Authorization' => 'Bearer ' . $apiKey,
		]);
		$text = trim((string)($data['choices'][0]['message']['content'] ?? ''));
		if ($text === '') {
			throw new RuntimeException('Mistral returned no classification text.');
		}

		$result = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
		if (!is_array($result)) {
			throw new RuntimeException('Mistral returned an invalid classification.');
		}
		$result['model'] = $model;

		return $result;
	}

	/** @return array<string, mixed> */
	private function classifyWithOllama(string $userId, string $prompt): array {
		$model = trim($this->config->getUserValue($userId, 'musiccurator', 'ollama_model', ''));
		if ($model === '') {
			throw new RuntimeException('Ollama is selected but no model name is configured.');
		}
		$baseUrl = rtrim(trim($this->config->getUserValue($userId, 'musiccurator', 'ollama_url', self::DEFAULT_OLLAMA_URL)), '/');
		if ($baseUrl === '') {
			$baseUrl = self::DEFAULT_OLLAMA_URL;
		}
		$this->assertSafeOllamaUrl($baseUrl);

		$payload = [
			'model' => $model,
			'messages' => [
				['role' => 'system', 'content' => $this->systemPrompt()],
				['role' => 'user', 'content' => $prompt],
			],
			'format' => $this->schema(),
			'stream' => false,
			'options' => ['temperature' => 0.1],
		];

		$data = $this->postJson($baseUrl . '/chat', $payload);
		$text = trim((string)($data['message']['content'] ?? ''));
		if ($text === '') {
			throw new RuntimeException('Ollama returned no classification text.');
		}

		$result = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
		if (!is_array($result)) {
			throw new RuntimeException('Ollama returned an invalid classification.');
		}
		$result['model'] = $model;

		return $result;
	}

	/**
	 * @param array<string, mixed> $payload
	 * @param array<string, string> $extraHeaders
	 * @return array<string, mixed>
	 */
	private function postJson(string $url, array $payload, array $extraHeaders = []): array {
		$client = $this->clientService->newClient();
		$response = $client->post($url, [
			'headers' => array_merge([
				'Accept' => 'application/json',
				'Content-Type' => 'application/json',
				'User-Agent' => 'MusicCurator/0.2.3 (https://github.com/Happyfeet01/musiccurator)',
			], $extraHeaders),
			'body' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
			'connect_timeout' => 10,
			'timeout' => 35,
			'http_errors' => false,
		]);

		$status = $response->getStatusCode();
		$body = (string)$response->getBody();
		if ($status < 200 || $status >= 300) {
			$detail = trim(mb_substr($body, 0, 400));
			throw new RuntimeException(sprintf('AI provider returned HTTP %d%s', $status, $detail !== '' ? ': ' . $detail : ''));
		}

		$data = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
		return is_array($data) ? $data : [];
	}

	/**
	 * @param list<array<string, mixed>> $tracks
	 * @return array<string, mixed>
	 */
	private function folderSummary(string $path, array $tracks): array {
		$artists = [];
		$albums = [];
		$sample = [];
		foreach ($tracks as $track) {
			$artist = trim((string)($track['artist'] ?? ''));
			$album = trim((string)($track['album'] ?? ''));
			if ($artist !== '') {
				$artists[mb_strtolower($artist)] = true;
			}
			if ($album !== '') {
				$albums[mb_strtolower($album)] = true;
			}
			if (count($sample) < 80) {
				$sample[] = [
					'filename' => (string)($track['filename'] ?? basename((string)($track['path'] ?? ''))),
					'title' => (string)($track['title'] ?? ''),
					'artist' => $artist,
					'album' => $album,
					'track' => (string)($track['track'] ?? ''),
					'genre' => (string)($track['genre'] ?? ''),
				];
			}
		}

		return [
			'path' => $path,
			'stats' => [
				'tracks' => count($tracks),
				'distinctArtists' => count($artists),
				'distinctAlbums' => count($albums),
				'playlistLikePath' => preg_match('/(^|\/)(playlists?|playlisten)(?:\s|\(|\/|$)/i', $path) === 1,
			],
			'tracks' => $sample,
		];
	}

	/** @param array<string, mixed> $summary */
	private function classificationPrompt(array $summary): string {
		return "Classify this music folder using only the supplied filenames, tags and statistics. Do not claim to hear or fingerprint audio.\n\n"
			. json_encode($summary, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
			. "\n\nReturn only the required JSON object. Prefer metadata mode for playlists, compilations, mixed folders, or uncertain cases. Recommend organize only for a coherent album where moving files into Artist/Album is likely safe.";
	}

	private function systemPrompt(): string {
		return 'You are MusicCurator AI Advisor. Classify music-library folder structure conservatively. You are an advisory layer, not an audio-recognition engine. Never invent evidence that is not present. Preserve playlist and compilation folder structure unless the evidence strongly indicates one coherent album.';
	}

	/** @return array<string, mixed> */
	private function schema(): array {
		return [
			'type' => 'object',
			'properties' => [
				'groupType' => ['type' => 'string', 'enum' => ['album', 'compilation', 'playlist', 'mixed', 'unknown']],
				'confidence' => ['type' => 'integer'],
				'recommendedMode' => ['type' => 'string', 'enum' => ['metadata', 'organize']],
				'reason' => ['type' => 'string'],
			],
			'required' => ['groupType', 'confidence', 'recommendedMode', 'reason'],
			'additionalProperties' => false,
		];
	}

	/** @param array<string, mixed> $result @return array<string, mixed> */
	private function normalizeResult(array $result): array {
		$groupType = strtolower(trim((string)($result['groupType'] ?? 'unknown')));
		if (!in_array($groupType, ['album', 'compilation', 'playlist', 'mixed', 'unknown'], true)) {
			$groupType = 'unknown';
		}
		$mode = strtolower(trim((string)($result['recommendedMode'] ?? 'metadata')));
		if (!in_array($mode, ['metadata', 'organize'], true)) {
			$mode = 'metadata';
		}

		return [
			'groupType' => $groupType,
			'confidence' => max(0, min(100, (int)($result['confidence'] ?? 0))),
			'recommendedMode' => $mode,
			'reason' => trim((string)($result['reason'] ?? 'No explanation returned.')),
			'model' => trim((string)($result['model'] ?? '')),
		];
	}

	private function assertSafeOllamaUrl(string $url): void {
		$parts = parse_url($url);
		$scheme = strtolower((string)($parts['scheme'] ?? ''));
		$host = strtolower((string)($parts['host'] ?? ''));
		if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
			throw new RuntimeException('Invalid Ollama base URL.');
		}

		// Keep the first local-AI implementation deliberately narrow. Remote
		// Ollama servers can be supported later with explicit trust controls.
		if (!in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
			throw new RuntimeException('For now, Ollama must run on the same server (localhost/127.0.0.1).');
		}
	}
}
