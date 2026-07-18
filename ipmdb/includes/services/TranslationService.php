<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| /httpdocs/site3/ipmdb/includes/services/TranslationService.php
|--------------------------------------------------------------------------
| IPMdb Translation Service
|--------------------------------------------------------------------------
|
| Creates, validates, compares, reviews, and approves translation entities.
|
| This service does not call an external translation provider directly.
| Provider adapters will later supply translated text and model metadata.
|
| A translation remains separate from its source.
| Provenance, language, method, confidence, and review remain visible.
|
| SQ assists.
| The Doer decides.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/Service.php';
require_once __DIR__ . '/ValidationService.php';
require_once dirname(__DIR__) . '/core/Entity.php';
require_once dirname(__DIR__) . '/core/EntityCollection.php';
require_once dirname(__DIR__) . '/schema/schema_registry.php';

final class TranslationService extends Service
{
    private ValidationService $validator;

    /**
     * Common language aliases normalized to BCP 47-style tags.
     *
     * @var array<string,string>
     */
    private array $languageAliases = [
        'english' => 'en',
        'french' => 'fr',
        'spanish' => 'es',
        'german' => 'de',
        'italian' => 'it',
        'portuguese' => 'pt',
        'dutch' => 'nl',
        'russian' => 'ru',
        'ukrainian' => 'uk',
        'arabic' => 'ar',
        'hebrew' => 'he',
        'hindi' => 'hi',
        'bengali' => 'bn',
        'punjabi' => 'pa',
        'urdu' => 'ur',
        'mandarin' => 'zh',
        'chinese' => 'zh',
        'japanese' => 'ja',
        'korean' => 'ko',
        'vietnamese' => 'vi',
        'thai' => 'th',
        'indonesian' => 'id',
        'tagalog' => 'tl',
        'filipino' => 'fil',
        'persian' => 'fa',
        'farsi' => 'fa',
        'turkish' => 'tr',
        'polish' => 'pl',
        'swedish' => 'sv',
        'norwegian' => 'no',
        'danish' => 'da',
        'finnish' => 'fi',
        'greek' => 'el',
        'czech' => 'cs',
        'romanian' => 'ro',
        'hungarian' => 'hu',
        'swahili' => 'sw',
        'latin' => 'la',
    ];

    public function __construct(
        array $config = [],
        array $context = [],
        ?ValidationService $validator = null
    ) {
        parent::__construct($config, $context);

        $this->validator = $validator
            ?? new ValidationService();

        $this->registerTranslationSchema();
    }

    /**
     * Create a new translation entity from supplied source and translation data.
     *
     * Required source fields:
     * - entity_id or source_entity_id
     * - entity_type
     * - language or source_language
     *
     * Required translation fields:
     * - target_language
     * - title
     * - content
     * - translator_id
     */
    public function create(
        array $source,
        array $translation
    ): Entity {
        $this->reset();

        $sourceEntityId = trim(
            (string)(
                $source['entity_id']
                ?? $source['source_entity_id']
                ?? ''
            )
        );

        $sourceEntityType = $this->normalizeKey(
            (string)(
                $source['entity_type']
                ?? $source['source_entity_type']
                ?? 'entity'
            )
        );

        $sourceLanguage = $this->normalizeLanguageTag(
            (string)(
                $source['language']
                ?? $source['source_language']
                ?? ''
            )
        );

        $targetLanguage = $this->normalizeLanguageTag(
            (string)(
                $translation['target_language']
                ?? ''
            )
        );

        $method = $this->normalizeMethod(
            (string)(
                $translation['translation_method']
                ?? 'human'
            )
        );

        $translatorType = $this->normalizeTranslatorType(
            (string)(
                $translation['translator_type']
                ?? (
                    $method === 'ai'
                        ? 'ai'
                        : 'person'
                )
            )
        );

        $translationId = trim(
            (string)(
                $translation['translation_id']
                ?? ''
            )
        );

        if ($translationId === '') {
            $translationId = $this->generateTranslationId();
        }

        $content = trim(
            (string)(
                $translation['content']
                ?? ''
            )
        );

        $data = [
            'translation_id' => $translationId,
            'entity_type' => 'translation',
            'source_entity_id' => $sourceEntityId,
            'source_entity_type' => $sourceEntityType,
            'source_version' => trim(
                (string)(
                    $source['version']
                    ?? $translation['source_version']
                    ?? ''
                )
            ),
            'source_language' => $sourceLanguage,
            'target_language' => $targetLanguage,
            'title' => trim(
                (string)(
                    $translation['title']
                    ?? ''
                )
            ),
            'summary' => trim(
                (string)(
                    $translation['summary']
                    ?? ''
                )
            ),
            'content' => $content,
            'translation_method' => $method,
            'translator_id' => trim(
                (string)(
                    $translation['translator_id']
                    ?? ''
                )
            ),
            'translator_type' => $translatorType,
            'translation_provider' => trim(
                (string)(
                    $translation['translation_provider']
                    ?? ''
                )
            ),
            'translation_model' => trim(
                (string)(
                    $translation['translation_model']
                    ?? ''
                )
            ),
            'translation_prompt_reference' => trim(
                (string)(
                    $translation['translation_prompt_reference']
                    ?? ''
                )
            ),
            'confidence' => $this->normalizeConfidence(
                $translation['confidence']
                ?? null
            ),
            'review_status' => $this->normalizeReviewStatus(
                (string)(
                    $translation['review_status']
                    ?? 'unreviewed'
                )
            ),
            'reviewer_id' => trim(
                (string)(
                    $translation['reviewer_id']
                    ?? ''
                )
            ),
            'review_notes' => trim(
                (string)(
                    $translation['review_notes']
                    ?? ''
                )
            ),
            'meaning_notes' => trim(
                (string)(
                    $translation['meaning_notes']
                    ?? ''
                )
            ),
            'terminology_notes' => trim(
                (string)(
                    $translation['terminology_notes']
                    ?? ''
                )
            ),
            'regional_context' => trim(
                (string)(
                    $translation['regional_context']
                    ?? ''
                )
            ),
            'status' => $this->normalizeStatus(
                (string)(
                    $translation['status']
                    ?? 'draft'
                )
            ),
            'version' => trim(
                (string)(
                    $translation['version']
                    ?? '1.0'
                )
            ),
            'visibility' => $this->normalizeVisibility(
                (string)(
                    $translation['visibility']
                    ?? 'public'
                )
            ),
            'license' => trim(
                (string)(
                    $translation['license']
                    ?? $source['license']
                    ?? ''
                )
            ),
            'source_reference' => trim(
                (string)(
                    $translation['source_reference']
                    ?? $source['source_reference']
                    ?? ''
                )
            ),
            'checksum' => $this->checksum(
                $content,
                $sourceEntityId,
                $sourceLanguage,
                $targetLanguage
            ),
            'locked' => (bool)(
                $translation['locked']
                ?? false
            ),
            'created_at' => trim(
                (string)(
                    $translation['created_at']
                    ?? $this->now()
                )
            ),
            'updated_at' => trim(
                (string)(
                    $translation['updated_at']
                    ?? $this->now()
                )
            ),
            'reviewed_at' => trim(
                (string)(
                    $translation['reviewed_at']
                    ?? ''
                )
            ),
            'approved_at' => trim(
                (string)(
                    $translation['approved_at']
                    ?? ''
                )
            ),
            'archived_at' => trim(
                (string)(
                    $translation['archived_at']
                    ?? ''
                )
            ),
        ];

        $entity = Entity::make(
            'translation',
            $data
        );

        $this->validateTranslationOrFail($entity);

        $this->addMessage(
            'Translation entity created.',
            [
                'translation_id' => $translationId,
                'source_entity_id' => $sourceEntityId,
                'source_language' => $sourceLanguage,
                'target_language' => $targetLanguage,
                'method' => $method,
            ]
        );

        return $entity;
    }

    /**
     * Prepare an AI translation record without inventing translated content.
     *
     * An external provider must supply title and content before validation.
     */
    public function prepareAiTranslation(
        Entity|array $source,
        string $targetLanguage,
        string $provider,
        string $model,
        string $translatorId = 'sq'
    ): array {
        $this->reset();

        $sourceData = $source instanceof Entity
            ? $source->toArray()
            : $source;

        $sourceLanguage = $this->normalizeLanguageTag(
            (string)(
                $sourceData['language']
                ?? $sourceData['source_language']
                ?? ''
            )
        );

        $targetLanguage = $this->normalizeLanguageTag(
            $targetLanguage
        );

        $sourceEntityId = trim(
            (string)(
                $sourceData['entity_id']
                ?? $sourceData['source_entity_id']
                ?? ''
            )
        );

        if ($sourceEntityId === '') {
            throw new InvalidArgumentException(
                'Source entity ID is required.'
            );
        }

        if (!$this->isValidLanguageTag($sourceLanguage)) {
            throw new InvalidArgumentException(
                'Source language must be a valid BCP 47-style tag.'
            );
        }

        if (!$this->isValidLanguageTag($targetLanguage)) {
            throw new InvalidArgumentException(
                'Target language must be a valid BCP 47-style tag.'
            );
        }

        if (
            strtolower($sourceLanguage)
            === strtolower($targetLanguage)
        ) {
            throw new InvalidArgumentException(
                'Source and target languages must differ.'
            );
        }

        $prepared = [
            'translation_id' => $this->generateTranslationId(),
            'entity_type' => 'translation',
            'source_entity_id' => $sourceEntityId,
            'source_entity_type' => $this->normalizeKey(
                (string)(
                    $sourceData['entity_type']
                    ?? 'entity'
                )
            ),
            'source_version' => trim(
                (string)(
                    $sourceData['version']
                    ?? ''
                )
            ),
            'source_language' => $sourceLanguage,
            'target_language' => $targetLanguage,
            'translation_method' => 'ai',
            'translator_id' => trim($translatorId),
            'translator_type' => 'ai',
            'translation_provider' => trim($provider),
            'translation_model' => trim($model),
            'review_status' => 'unreviewed',
            'status' => 'draft',
            'version' => '1.0',
            'visibility' => 'public',
            'locked' => false,
            'created_at' => $this->now(),
            'updated_at' => $this->now(),
        ];

        $this->addMessage(
            'AI translation request prepared.',
            [
                'translation_id' => $prepared['translation_id'],
                'source_entity_id' => $sourceEntityId,
                'target_language' => $targetLanguage,
                'provider' => $provider,
                'model' => $model,
            ]
        );

        return $prepared;
    }

    public function validateTranslation(
        Entity $translation
    ): bool {
        $this->reset();

        if ($translation->entityType() !== 'translation') {
            $this->addError(
                'Entity must be a translation.',
                [
                    'entity_type' => $translation->entityType(),
                ]
            );

            return false;
        }

        if (!$this->validator->validateEntity($translation)) {
            foreach ($this->validator->errorMessages() as $message) {
                $this->addError($message);
            }
        }

        $sourceLanguage = $this->normalizeLanguageTag(
            (string)$translation->get('source_language', '')
        );

        $targetLanguage = $this->normalizeLanguageTag(
            (string)$translation->get('target_language', '')
        );

        if (!$this->isValidLanguageTag($sourceLanguage)) {
            $this->addError(
                'Source language must be a valid BCP 47-style tag.'
            );
        }

        if (!$this->isValidLanguageTag($targetLanguage)) {
            $this->addError(
                'Target language must be a valid BCP 47-style tag.'
            );
        }

        if (
            $sourceLanguage !== ''
            && $targetLanguage !== ''
            && strtolower($sourceLanguage)
                === strtolower($targetLanguage)
        ) {
            $this->addError(
                'Source and target languages must differ.'
            );
        }

        if (
            trim(
                (string)$translation->get(
                    'source_entity_id',
                    ''
                )
            ) === ''
        ) {
            $this->addError(
                'Source entity ID is required.'
            );
        }

        if (
            trim(
                (string)$translation->get(
                    'translator_id',
                    ''
                )
            ) === ''
        ) {
            $this->addError(
                'Translator attribution is required.'
            );
        }

        $method = $this->normalizeMethod(
            (string)$translation->get(
                'translation_method',
                ''
            )
        );

        if ($method === 'ai') {
            if (
                trim(
                    (string)$translation->get(
                        'translation_provider',
                        ''
                    )
                ) === ''
            ) {
                $this->addError(
                    'AI translations require a provider.'
                );
            }

            if (
                trim(
                    (string)$translation->get(
                        'translation_model',
                        ''
                    )
                ) === ''
            ) {
                $this->addError(
                    'AI translations require a model.'
                );
            }
        }

        $reviewStatus = $this->normalizeReviewStatus(
            (string)$translation->get(
                'review_status',
                ''
            )
        );

        if (
            $reviewStatus === 'approved'
            && trim(
                (string)$translation->get(
                    'reviewer_id',
                    ''
                )
            ) === ''
        ) {
            $this->addError(
                'Approved translations require reviewer attribution.'
            );
        }

        $confidence = $translation->get('confidence');

        if (
            $confidence !== null
            && $confidence !== ''
            && (
                !is_numeric($confidence)
                || (float)$confidence < 0
                || (float)$confidence > 100
            )
        ) {
            $this->addError(
                'Translation confidence must be between 0 and 100.'
            );
        }

        if ($this->succeeded()) {
            $this->addMessage(
                'Translation validation passed.',
                [
                    'translation_id' =>
                        $translation->get('translation_id'),
                ]
            );
        }

        return $this->succeeded();
    }

    public function validateTranslationOrFail(
        Entity $translation
    ): Entity {
        if (!$this->validateTranslation($translation)) {
            $messages = array_map(
                static fn (array $error): string =>
                    (string)($error['message'] ?? ''),
                $this->errors()
            );

            throw new EntityValidationException(
                array_values(
                    array_filter($messages)
                )
            );
        }

        return $translation;
    }

    public function submitForReview(
        Entity $translation
    ): Entity {
        $this->assertTranslation($translation);
        $this->assertUnlocked($translation);

        $this->validateTranslationOrFail($translation);

        $translation
            ->set('review_status', 'unreviewed')
            ->set('status', 'proposed')
            ->set('updated_at', $this->now());

        $this->addMessage(
            'Translation submitted for review.',
            [
                'translation_id' =>
                    $translation->get('translation_id'),
            ]
        );

        return $translation;
    }

    public function review(
        Entity $translation,
        string $reviewerId,
        string $reviewStatus,
        string $notes = ''
    ): Entity {
        $this->assertTranslation($translation);
        $this->assertUnlocked($translation);

        $reviewerId = trim($reviewerId);

        if ($reviewerId === '') {
            throw new InvalidArgumentException(
                'Reviewer ID is required.'
            );
        }

        $reviewStatus = $this->normalizeReviewStatus(
            $reviewStatus
        );

        $allowed = [
            'machine_checked',
            'human_reviewed',
            'community_reviewed',
            'approved',
            'disputed',
            'rejected',
        ];

        if (!in_array($reviewStatus, $allowed, true)) {
            throw new InvalidArgumentException(
                'Unsupported review status.'
            );
        }

        $translation
            ->set('reviewer_id', $reviewerId)
            ->set('review_status', $reviewStatus)
            ->set('review_notes', trim($notes))
            ->set('reviewed_at', $this->now())
            ->set('updated_at', $this->now());

        if ($reviewStatus === 'approved') {
            $translation
                ->set('approved_at', $this->now())
                ->set('status', 'verified');
        } elseif ($reviewStatus === 'disputed') {
            $translation->set('status', 'disputed');
        } elseif ($reviewStatus === 'rejected') {
            $translation->set('status', 'archived');
        }

        $this->addMessage(
            'Translation review recorded.',
            [
                'translation_id' =>
                    $translation->get('translation_id'),
                'reviewer_id' => $reviewerId,
                'review_status' => $reviewStatus,
            ]
        );

        return $translation;
    }

    public function approve(
        Entity $translation,
        string $reviewerId,
        string $notes = ''
    ): Entity {
        return $this->review(
            $translation,
            $reviewerId,
            'approved',
            $notes
        );
    }

    public function dispute(
        Entity $translation,
        string $reviewerId,
        string $notes
    ): Entity {
        if (trim($notes) === '') {
            throw new InvalidArgumentException(
                'A disputed translation requires review notes.'
            );
        }

        return $this->review(
            $translation,
            $reviewerId,
            'disputed',
            $notes
        );
    }

    public function archive(
        Entity $translation,
        string $reason = ''
    ): Entity {
        $this->assertTranslation($translation);
        $this->assertUnlocked($translation);

        $translation
            ->set('status', 'archived')
            ->set('archived_at', $this->now())
            ->set('updated_at', $this->now());

        if (trim($reason) !== '') {
            $existing = trim(
                (string)$translation->get(
                    'review_notes',
                    ''
                )
            );

            $translation->set(
                'review_notes',
                trim(
                    $existing
                    . ($existing !== '' ? "\n\n" : '')
                    . 'Archive reason: '
                    . trim($reason)
                )
            );
        }

        $this->addMessage(
            'Translation archived.',
            [
                'translation_id' =>
                    $translation->get('translation_id'),
            ]
        );

        return $translation;
    }

    /**
     * Compare two translations without deciding which is authoritative.
     */
    public function compare(
        Entity $left,
        Entity $right
    ): array {
        $this->assertTranslation($left);
        $this->assertTranslation($right);

        $fields = [
            'source_entity_id',
            'source_language',
            'target_language',
            'title',
            'summary',
            'content',
            'translation_method',
            'translator_id',
            'translation_provider',
            'translation_model',
            'confidence',
            'review_status',
            'meaning_notes',
            'terminology_notes',
            'regional_context',
            'status',
            'version',
        ];

        $differences = [];

        foreach ($fields as $field) {
            $leftValue = $left->get($field);
            $rightValue = $right->get($field);

            if ($leftValue !== $rightValue) {
                $differences[$field] = [
                    'left' => $leftValue,
                    'right' => $rightValue,
                ];
            }
        }

        return [
            'left_translation_id' =>
                $left->get('translation_id'),
            'right_translation_id' =>
                $right->get('translation_id'),
            'same_source' =>
                $left->get('source_entity_id')
                === $right->get('source_entity_id'),
            'same_target_language' =>
                strtolower(
                    (string)$left->get(
                        'target_language',
                        ''
                    )
                )
                === strtolower(
                    (string)$right->get(
                        'target_language',
                        ''
                    )
                ),
            'difference_count' => count($differences),
            'differences' => $differences,
        ];
    }

    public function translationsForSource(
        EntityCollection $translations,
        string $sourceEntityId,
        ?string $targetLanguage = null
    ): EntityCollection {
        $sourceEntityId = trim($sourceEntityId);

        $filtered = $translations->where(
            'source_entity_id',
            $sourceEntityId
        );

        if ($targetLanguage !== null) {
            $targetLanguage = $this->normalizeLanguageTag(
                $targetLanguage
            );

            $filtered = $filtered->filter(
                static function (
                    Entity $translation
                ) use ($targetLanguage): bool {
                    return strtolower(
                        (string)$translation->get(
                            'target_language',
                            ''
                        )
                    ) === strtolower($targetLanguage);
                }
            );
        }

        return $filtered;
    }

    public function preferredTranslation(
        EntityCollection $translations,
        string $sourceEntityId,
        string $targetLanguage
    ): ?Entity {
        $matches = $this->translationsForSource(
            $translations,
            $sourceEntityId,
            $targetLanguage
        );

        if ($matches->isEmpty()) {
            return null;
        }

        $ranked = $matches->all();

        usort(
            $ranked,
            function (
                Entity $left,
                Entity $right
            ): int {
                $leftScore = $this->translationScore($left);
                $rightScore = $this->translationScore($right);

                return $rightScore <=> $leftScore;
            }
        );

        return $ranked[0] ?? null;
    }

    public function normalizeLanguageTag(
        string $language
    ): string {
        $language = trim($language);

        if ($language === '') {
            return '';
        }

        $aliasKey = strtolower($language);

        if (isset($this->languageAliases[$aliasKey])) {
            return $this->languageAliases[$aliasKey];
        }

        $language = str_replace('_', '-', $language);

        $parts = array_values(
            array_filter(
                explode('-', $language),
                static fn (string $part): bool =>
                    $part !== ''
            )
        );

        if ($parts === []) {
            return '';
        }

        $normalized = [];

        foreach ($parts as $index => $part) {
            if ($index === 0) {
                $normalized[] = strtolower($part);
                continue;
            }

            if (strlen($part) === 2 || strlen($part) === 3) {
                $normalized[] = strtoupper($part);
                continue;
            }

            if (strlen($part) === 4) {
                $normalized[] =
                    strtoupper(substr($part, 0, 1))
                    . strtolower(substr($part, 1));
                continue;
            }

            $normalized[] = strtolower($part);
        }

        return implode('-', $normalized);
    }

    public function isValidLanguageTag(
        string $language
    ): bool {
        $language = $this->normalizeLanguageTag(
            $language
        );

        if ($language === '') {
            return false;
        }

        return preg_match(
            '/^[a-zA-Z]{2,8}(?:-[a-zA-Z0-9]{1,8})*$/',
            $language
        ) === 1;
    }

    public function checksum(
        string $content,
        string $sourceEntityId = '',
        string $sourceLanguage = '',
        string $targetLanguage = ''
    ): string {
        return hash(
            'sha256',
            implode(
                "\n",
                [
                    trim($sourceEntityId),
                    strtolower(trim($sourceLanguage)),
                    strtolower(trim($targetLanguage)),
                    trim($content),
                ]
            )
        );
    }

    private function registerTranslationSchema(): void
    {
        if (!SchemaRegistry::exists('translation')) {
            SchemaRegistry::register(
                'translation',
                'translation_schema.php'
            );
        }
    }

    private function assertTranslation(
        Entity $entity
    ): void {
        if ($entity->entityType() !== 'translation') {
            throw new InvalidArgumentException(
                'Entity must be a translation.'
            );
        }
    }

    private function assertUnlocked(
        Entity $entity
    ): void {
        if ($entity->isLocked()) {
            throw new RuntimeException(
                'Translation is locked.'
            );
        }
    }

    private function normalizeMethod(
        string $method
    ): string {
        $method = $this->normalizeKey($method);

        $allowed = [
            'human',
            'ai',
            'hybrid',
            'community',
            'imported',
        ];

        return in_array($method, $allowed, true)
            ? $method
            : 'human';
    }

    private function normalizeTranslatorType(
        string $type
    ): string {
        $type = $this->normalizeKey($type);

        $allowed = [
            'person',
            'organization',
            'ai',
            'community',
            'unknown',
        ];

        return in_array($type, $allowed, true)
            ? $type
            : 'unknown';
    }

    private function normalizeReviewStatus(
        string $status
    ): string {
        $status = $this->normalizeKey($status);

        $allowed = [
            'unreviewed',
            'machine_checked',
            'human_reviewed',
            'community_reviewed',
            'approved',
            'disputed',
            'rejected',
        ];

        return in_array($status, $allowed, true)
            ? $status
            : 'unreviewed';
    }

    private function normalizeStatus(
        string $status
    ): string {
        $status = $this->normalizeKey($status);

        $allowed = [
            'draft',
            'proposed',
            'active',
            'verified',
            'archived',
            'disputed',
        ];

        return in_array($status, $allowed, true)
            ? $status
            : 'draft';
    }

    private function normalizeVisibility(
        string $visibility
    ): string {
        $visibility = $this->normalizeKey(
            $visibility
        );

        $allowed = [
            'public',
            'unlisted',
            'restricted',
            'private',
        ];

        return in_array(
            $visibility,
            $allowed,
            true
        )
            ? $visibility
            : 'public';
    }

    private function normalizeConfidence(
        mixed $confidence
    ): ?float {
        if (
            $confidence === null
            || $confidence === ''
        ) {
            return null;
        }

        if (!is_numeric($confidence)) {
            throw new InvalidArgumentException(
                'Translation confidence must be numeric.'
            );
        }

        $confidence = (float)$confidence;

        if ($confidence < 0 || $confidence > 100) {
            throw new InvalidArgumentException(
                'Translation confidence must be between 0 and 100.'
            );
        }

        return round($confidence, 2);
    }

    private function generateTranslationId(): string
    {
        try {
            $random = strtoupper(
                bin2hex(random_bytes(6))
            );
        } catch (Throwable) {
            $random = strtoupper(
                substr(
                    hash(
                        'sha256',
                        uniqid('', true)
                        . microtime(true)
                    ),
                    0,
                    12
                )
            );
        }

        return 'TRN-'
            . gmdate('Ymd-His')
            . '-'
            . $random;
    }

    private function translationScore(
        Entity $translation
    ): int {
        $score = 0;

        $reviewStatus = (string)$translation->get(
            'review_status',
            ''
        );

        $reviewScores = [
            'approved' => 600,
            'human_reviewed' => 500,
            'community_reviewed' => 450,
            'machine_checked' => 300,
            'unreviewed' => 100,
            'disputed' => 25,
            'rejected' => 0,
        ];

        $score += $reviewScores[$reviewStatus] ?? 0;

        $status = (string)$translation->get(
            'status',
            ''
        );

        $statusScores = [
            'verified' => 300,
            'active' => 250,
            'proposed' => 150,
            'draft' => 100,
            'disputed' => 25,
            'archived' => 0,
        ];

        $score += $statusScores[$status] ?? 0;

        $method = (string)$translation->get(
            'translation_method',
            ''
        );

        $methodScores = [
            'hybrid' => 100,
            'human' => 90,
            'community' => 80,
            'ai' => 60,
            'imported' => 40,
        ];

        $score += $methodScores[$method] ?? 0;

        $confidence = $translation->get(
            'confidence'
        );

        if (is_numeric($confidence)) {
            $score += (int)round(
                (float)$confidence
            );
        }

        return $score;
    }

    public function diagnostics(): array
    {
        return array_merge(
            parent::diagnostics(),
            [
                'schema_registered' =>
                    SchemaRegistry::exists(
                        'translation'
                    ),
                'language_alias_count' =>
                    count($this->languageAliases),
                'language_standard' =>
                    'BCP 47',
                'source_overwrite_allowed' =>
                    false,
            ]
        );
    }
}