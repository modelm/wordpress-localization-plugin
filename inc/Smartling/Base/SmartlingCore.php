<?php
namespace Smartling\Base;

use Exception;
use Smartling\ContentTypes\ContentTypeAttachment;
use Smartling\ContentTypes\ContentTypeCategory;
use Smartling\ContentTypes\ContentTypeNavigationMenu;
use Smartling\ContentTypes\ContentTypePostTag;
use Smartling\DbAl\WordpressContentEntities\EntityAbstract;
use Smartling\DbAl\WordpressContentEntities\MenuItemEntity;
use Smartling\DbAl\WordpressContentEntities\WidgetEntity;
use Smartling\Exception\BlogNotFoundException;
use Smartling\Exception\SmartlingDbException;
use Smartling\Exception\SmartlingExceptionAbstract;
use Smartling\Helpers\ArrayHelper;
use Smartling\Helpers\CommonLogMessagesTrait;
use Smartling\Helpers\EventParameters\ProcessRelatedContentParams;
use Smartling\Helpers\WordpressContentTypeHelper;
use Smartling\Queue\Queue;
use Smartling\Settings\ConfigurationProfileEntity;
use Smartling\Specific\SurveyMonkey\PrepareRelatedSMSpecificTrait;
use Smartling\Submissions\SubmissionEntity;

/**
 * Class SmartlingCore
 * @package Smartling\Base
 */
class SmartlingCore extends SmartlingCoreAbstract
{

    use SmartlingCoreTrait;

    use PrepareRelatedSMSpecificTrait;

    use SmartlingCoreExportApi;

    use CommonLogMessagesTrait;

    public function __construct()
    {
        add_action(ExportedAPI::ACTION_SMARTLING_SEND_FILE_FOR_TRANSLATION, [$this, 'sendForTranslationBySubmission']);
        add_action(ExportedAPI::ACTION_SMARTLING_DOWNLOAD_TRANSLATION, [$this, 'downloadTranslationBySubmission',]);
        add_action(ExportedAPI::ACTION_SMARTLING_CLONE_CONTENT, [$this, 'cloneWithoutTranslation']);
        add_action(ExportedAPI::ACTION_SMARTLING_REGENERATE_THUMBNAILS, [$this, 'regenerateTargetThumbnailsBySubmission']);

        add_filter(ExportedAPI::FILTER_SMARTLING_PREPARE_TARGET_CONTENT, [$this, 'prepareTargetContent']);
    }

    /**
     * current mode to send data to Smartling
     */
    const SEND_MODE = self::SEND_MODE_FILE;

    /**
     * @param SubmissionEntity $submission
     * @param string           $contentType
     * @param array            $accumulator
     */
    private function processRelatedTerm(SubmissionEntity $submission, $contentType, & $accumulator)
    {
        $this->getLogger()->debug(vsprintf('Searching for terms (%s) related to submission = \'%s\'.', [
            $contentType,
            $submission->getId(),
        ]));

        $params = new ProcessRelatedContentParams($submission, $contentType, $accumulator);

        do_action(ExportedAPI::ACTION_SMARTLING_PROCESSOR_RELATED_CONTENT, $params);

    }

    /**
     * @param SubmissionEntity $submission
     * @param string           $contentType
     * @param array            $accumulator
     *
     * @throws BlogNotFoundException
     */
    private function processRelatedMenu(SubmissionEntity $submission, $contentType, &$accumulator)
    {
        $params = new ProcessRelatedContentParams($submission, $contentType, $accumulator);

        do_action(ExportedAPI::ACTION_SMARTLING_PROCESSOR_RELATED_CONTENT, $params);
    }

    /**
     * @param SubmissionEntity $submission
     *
     * @throws BlogNotFoundException
     */
    public function prepareRelatedSubmissions(SubmissionEntity $submission)
    {
        $this->getLogger()->info(vsprintf('Searching for related content for submission = \'%s\' for translation', [
            $submission->getId(),
        ]));

        $tagretContentId = $submission->getTargetId();

        $originalEntity = $this->getContentHelper()->readSourceContent($submission);
        $relatedContentTypes = $originalEntity->getRelatedTypes();
        $accumulator = [
            ContentTypeCategory::WP_CONTENT_TYPE => [],
            ContentTypePostTag::WP_CONTENT_TYPE => [],
        ];

        try {
            if (!empty($relatedContentTypes)) {
                foreach ($relatedContentTypes as $contentType) {
                    // SM Specific
                    try {
                        $this->processMediaAttachedToWidgetSM($submission, $contentType);
                    } catch (\Exception $e) {
                        $this->getLogger()
                            ->warning(vsprintf('An unhandled exception occurred while processing related media attachments for SM Widgets submission=%s', [$submission->getId()]));
                    }

                    try {
                        $this->processTestimonialAttachedToWidgetSM($submission, $contentType);
                    } catch (\Exception $e) {
                        $this->getLogger()
                            ->warning(vsprintf('An unhandled exception occurred while processing related testimonial for SM Widgets submission=%s', [$submission->getId()]));
                    }

                    try {
                        $this->processTestimonialsAttachedToWidgetSM($submission, $contentType);
                    } catch (\Exception $e) {
                        $this->getLogger()
                            ->warning(vsprintf('An unhandled exception occurred while processing related testimonials for SM Widgets submission=%s', [$submission->getId()]));
                    }

                    //Standard
                    try {
                        $this->processRelatedTerm($submission, $contentType, $accumulator);
                    } catch (\Exception $e) {
                        $this->getLogger()
                            ->warning(vsprintf('An unhandled exception occurred while processing related terms for submission=%s', [$submission->getId()]));
                    }
                    try {
                        $this->processRelatedMenu($submission, $contentType, $accumulator);
                    } catch (\Exception $e) {
                        $this->getLogger()
                            ->warning(vsprintf('An unhandled exception occurred while processing related menu for submission=%s', [$submission->getId()]));
                    }

                }
            }

            if ($submission->getContentType() !== ContentTypeNavigationMenu::WP_CONTENT_TYPE) {

                $this->getContentHelper()->ensureTarget($submission);

                $this->getLogger()
                    ->debug(vsprintf('Preparing to assign accumulator: %s', [var_export($accumulator, true)]));

                foreach ($accumulator as $type => $ids) {
                    $this->getLogger()
                        ->debug(vsprintf('Assigning term (type = \'%s\', ids = \'%s\') to content (type = \'%s\', id = \'%s\') on blog= \'%s\'.', [
                            $type,
                            implode(',', $ids),
                            $submission->getContentType(),
                            $tagretContentId,
                            $submission->getTargetBlogId(),
                        ]));

                    wp_set_post_terms($submission->getTargetId(), $ids, $type);

                }

                $this->getContentHelper()->ensureRestoredBlogId();
            } else {
                $this->getCustomMenuHelper()->assignMenuItemsToMenu(
                    (int)$submission->getTargetId(),
                    (int)$submission->getTargetBlogId(),
                    $accumulator[ContentTypeNavigationMenu::WP_CONTENT_TYPE]
                );
            }
        } catch (BlogNotFoundException $e) {
            $message = vsprintf('Inconsistent multisite installation. %s', [$e->getMessage()]);
            $this->getLogger()->emergency($message);

            throw $e;
        }
    }

    /**
     * Sends data to smartling directly
     *
     * @param SubmissionEntity $submission
     * @param string           $xmlFileContent
     *
     * @return bool
     */
    protected function sendStream(SubmissionEntity $submission, $xmlFileContent)
    {
        return $this->getApiWrapper()->uploadContent($submission, $xmlFileContent);
    }

    /**
     * Sends data to smartling via temporary file
     *
     * @param SubmissionEntity $submission
     * @param string           $xmlFileContent
     * @param array            $smartlingLocaleList
     *
     * @return bool
     */
    protected function sendFile(SubmissionEntity $submission, $xmlFileContent, array $smartlingLocaleList = [])
    {
        $tmp_file = tempnam(sys_get_temp_dir(), '_smartling_temp_');

        file_put_contents($tmp_file, $xmlFileContent);

        $result = $this->getApiWrapper()->uploadContent($submission, '', $tmp_file, $smartlingLocaleList);

        unlink($tmp_file);

        return $result;
    }

    /**
     * @param SubmissionEntity $entity
     *
     * @return EntityAbstract
     */
    private function getContentIOWrapper(SubmissionEntity $entity)
    {
        return $this->getContentIoFactory()->getMapper($entity->getContentType());
    }

    /**
     * Checks and updates submission with given ID
     *
     * @param $id
     *
     * @return array of error messages
     */
    public function checkSubmissionById($id)
    {
        $messages = [];

        try {
            $submission = $this->loadSubmissionEntityById($id);

            $this->checkSubmissionByEntity($submission);
        } catch (SmartlingExceptionAbstract $e) {
            $messages[] = $e->getMessage();
        } catch (Exception $e) {
            $messages[] = $e->getMessage();
        }

        return $messages;
    }

    /**
     * Checks and updates given submission entity
     *
     * @param SubmissionEntity $submission
     *
     * @return array of error messages
     */
    public function checkSubmissionByEntity(SubmissionEntity $submission)
    {
        $messages = [];

        try {
            $this->getLogger()->info(vsprintf(self::$MSG_CRON_CHECK, [
                $submission->getId(),
                $submission->getStatus(),
                $submission->getContentType(),
                $submission->getSourceBlogId(),
                $submission->getSourceId(),
                $submission->getTargetBlogId(),
                $submission->getTargetLocale(),
            ]));

            $submission = $this->getApiWrapper()->getStatus($submission);

            $this->getLogger()->info(vsprintf(self::$MSG_CRON_CHECK_RESULT, [
                $submission->getContentType(),
                $submission->getSourceBlogId(),
                $submission->getSourceId(),
                $submission->getTargetLocale(),
                $submission->getApprovedStringCount(),
                $submission->getCompletedStringCount(),
            ]));


            $submission = $this->getSubmissionManager()->storeEntity($submission);
        } catch (SmartlingExceptionAbstract $e) {
            $messages[] = $e->getMessage();
        } catch (Exception $e) {
            $messages[] = $e->getMessage();
        }

        return $messages;
    }

    /**
     * @param $id
     *
     * @return mixed
     * @throws SmartlingDbException
     */
    private function loadSubmissionEntityById($id)
    {
        $params = [
            'id' => $id,
        ];

        $entities = $this->getSubmissionManager()->find($params);

        if (count($entities) > 0) {
            return reset($entities);
        } else {
            $message = vsprintf('Requested SubmissionEntity with id=%s does not exist.', [$id]);

            $this->getLogger()->error($message);
            throw new SmartlingDbException($message);
        }
    }

    /**
     * @param array $items
     *
     * @return array
     * @throws SmartlingDbException
     */
    public function bulkCheckByIds(array $items)
    {
        $results = [];
        foreach ($items as $item) {
            /** @var SubmissionEntity $entity */
            try {
                $entity = $this->loadSubmissionEntityById($item);
            } catch (SmartlingDbException $e) {
                $this->getLogger()->error('Requested submission that does not exist: ' . (int)$item);
                continue;
            }
            if ($entity->getStatus() === SubmissionEntity::SUBMISSION_STATUS_IN_PROGRESS) {
                $this->checkSubmissionByEntity($entity);
                $this->checkEntityForDownload($entity);
                $results[] = $entity;
            }
        }

        return $results;
    }

    /**
     * @param SubmissionEntity $entity
     */
    public function checkEntityForDownload(SubmissionEntity $entity)
    {
        if (100 === $entity->getCompletionPercentage()) {

            $template = 'Cron Job enqueues content to download queue for submission id = \'%s\' with status = \'%s\' for entity = \'%s\', blog = \'%s\', id = \'%s\', targetBlog = \'%s\', locale = \'%s\'.';

            $message = vsprintf($template, [
                $entity->getId(),
                $entity->getStatus(),
                $entity->getContentType(),
                $entity->getSourceBlogId(),
                $entity->getSourceId(),
                $entity->getTargetBlogId(),
                $entity->getTargetLocale(),
            ]);

            $this->getLogger()->info($message);

            $this->getQueue()->enqueue([$entity->getId()], Queue::QUEUE_NAME_DOWNLOAD_QUEUE);
        }
    }

    /**
     * @param ConfigurationProfileEntity $profile
     *
     * @return array
     */
    public function getProjectLocales(ConfigurationProfileEntity $profile)
    {
        $cacheKey = 'profile.locales.' . $profile->getId();
        $cached = $this->getCache()->get($cacheKey);

        if (false === $cached) {
            $cached = $this->getApiWrapper()->getSupportedLocales($profile);
            $this->getCache()->set($cacheKey, $cached);
        }

        return $cached;
    }

    public function handleBadBlogId(SubmissionEntity $submission)
    {
        $profileMainId = $submission->getSourceBlogId();

        $profiles = $this->getSettingsManager()->findEntityByMainLocale($profileMainId);
        if (0 < count($profiles)) {

            $this->getLogger()->warning(vsprintf('Found broken profile. Id:%s. Deactivating.', [
                $profileMainId,
            ]));

            /**
             * @var ConfigurationProfileEntity $profile
             */
            $profile = reset($profiles);
            $profile->setIsActive(0);
            $this->getSettingsManager()->storeEntity($profile);
        }
    }

    /**
     * Forces image thumbnail re-generation
     *
     * @param SubmissionEntity $submission
     *
     * @throws BlogNotFoundException
     */
    public function regenerateTargetThumbnailsBySubmission(SubmissionEntity $submission)
    {
        $this->getLogger()
            ->debug(vsprintf('Starting thumbnails regeneration for blog = \'%s\' attachment id = \'%s\'.', [
                $submission->getTargetBlogId(),
                $submission->getTargetId(),
            ]));

        if (ContentTypeAttachment::WP_CONTENT_TYPE !== $submission->getContentType()) {
            return;
        }

        $this->getContentHelper()->ensureTarget($submission);

        $originalImage = get_attached_file($submission->getTargetId());

        if (!function_exists('wp_generate_attachment_metadata')) {
            include_once(ABSPATH . 'wp-admin/includes/image.php'); //including the attachment function
        }

        $metadata = wp_generate_attachment_metadata($submission->getTargetId(), $originalImage);

        if (is_wp_error($metadata)) {

            $this->getLogger()
                ->error(vsprintf('Error occurred while regenerating thumbnails for blog=\'%s\' attachment id=\'%s\'. Message:\'%s\'.', [
                    $submission->getTargetBlogId(),
                    $submission->getTargetId(),
                    $metadata->get_error_message(),
                ]));
        }

        if (empty($metadata)) {
            $this->getLogger()
                ->error(vsprintf('Couldn\'t regenerate thumbnails for blog=\'%s\' attachment id=\'%s\'.', [
                    $submission->getTargetBlogId(),
                    $submission->getTargetId(),
                ]));
        }

        wp_update_attachment_metadata($submission->getTargetId(), $metadata);

        $this->getContentHelper()->ensureRestoredBlogId();
    }
}
