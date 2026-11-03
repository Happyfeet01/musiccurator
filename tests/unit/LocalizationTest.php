<?php

declare(strict_types=1);

namespace OCA\MusicCurator\Tests\Unit;

use OCA\MusicCurator\Service\AiAdvisorService;
use OCA\MusicCurator\Service\ProviderCredentialsService;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class LocalizationTest extends TestCase {
	public function testAiExplanationLanguageDoesNotChangeMachineReadableSchema(): void {
		$schemas = [];
		foreach (['de', 'en'] as $language) {
			$l10n = $this->createMock(IL10N::class);
			$l10n->method('getLanguageCode')->willReturn($language);
			$service = new AiAdvisorService($this->createMock(IClientService::class), $this->createMock(IConfig::class),
				$this->createMock(ProviderCredentialsService::class), $l10n);
			$prompt = (new ReflectionMethod($service, 'systemPrompt'))->invoke($service);
			self::assertStringContainsString($language === 'de' ? 'Write the reason in German.' : 'Write the reason in English.', $prompt);
			self::assertStringContainsString('Keep all JSON keys and enum values unchanged.', $prompt);
			$schemas[] = (new ReflectionMethod($service, 'schema'))->invoke($service);
		}
		self::assertSame($schemas[0], $schemas[1]);
	}
}
