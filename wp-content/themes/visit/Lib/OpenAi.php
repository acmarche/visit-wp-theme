<?php

declare(strict_types=1);

namespace VisitMarche\ThemeWp\Lib;

use RuntimeException;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use VisitMarche\ThemeWp\Enums\LanguageEnum;
use VisitMarche\ThemeWp\Repository\TranslationRepository;

class OpenAi
{
    private const string OPENAI_API_URL = 'https://api.openai.com/v1/chat/completions';
    private const string DEFAULT_MODEL = 'gpt-4o-mini';

    private static ?self $instance = null;

    private readonly TranslationRepository $repository;

    private string $promptTemplate;

    private function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $apiKey,
        private readonly string $model = self::DEFAULT_MODEL,
    ) {
        global $wpdb;
        $this->repository = new TranslationRepository($wpdb);
        $this->promptTemplate = file_get_contents(get_template_directory().'/translations/prompt.txt');
    }

    /**
     * Translate text to the given language. Returns cached result if available.
     */
    public function translate(string $text, LanguageEnum $language): string
    {
        if (trim($text) === '') {
            return '';
        }

        if ($language === LanguageEnum::FRENCH) {
            return $text;
        }

        $cached = $this->repository->findTranslation($text, $language->value);
        if ($cached !== null) {
            return $cached;
        }

        $translated = $this->callOpenAi($text, $language);
        $this->repository->saveTranslation($text, $translated, $language->value);

        return $translated;
    }

    /**
     * Translate and bypass cache (forces a fresh API call, then saves the result).
     */
    public function translateFresh(string $text, LanguageEnum $language): string
    {
        if (trim($text) === '') {
            return '';
        }

        $this->repository->deleteTranslation($text, $language->value);

        $translated = $this->callOpenAi($text, $language);
        $this->repository->saveTranslation($text, $translated, $language->value);

        return $translated;
    }

    public function clearCache(): void
    {
        $this->repository->truncate();
    }

    private function callOpenAi(string $text, LanguageEnum $language): string
    {
        $systemPrompt = str_replace('{TARGET_LANGUAGE}', $language->label(), $this->promptTemplate);

        $response = $this->httpClient->request('POST', self::OPENAI_API_URL, [
            'headers' => [
                'Authorization' => 'Bearer '.$this->apiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'model' => $this->model,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $text],
                ],
                'temperature' => 0.3,
            ],
        ]);

        $data = $response->toArray();

        $content = $data['choices'][0]['message']['content'] ?? throw new RuntimeException(
            'Unexpected OpenAI response: no content in choices',
        );

        return trim($content, " \t\n\r\0\x0B\"");
    }

    /**
     * Get or create the singleton instance.
     */
    public static function create(?string $model = null): self
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $apiKey = $_ENV['OPENAI_API_KEY'] ?? throw new RuntimeException(
            'OPENAI_API_KEY is not set in environment',
        );

        self::$instance = new self(
            httpClient: HttpClient::create(),
            apiKey: $apiKey,
            model: $model ?? self::DEFAULT_MODEL,
        );

        return self::$instance;
    }
}
