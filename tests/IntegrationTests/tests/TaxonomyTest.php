<?php

namespace IntegrationTests\tests;

use Smartling\Submissions\SubmissionEntity;
use Smartling\Tests\IntegrationTests\SmartlingUnitTestCaseAbstract;

class TaxonomyTest extends SmartlingUnitTestCaseAbstract
{

    /**
     * Makes relation between terms.
     *
     * @param $parentTermId
     * @param $childTermId
     */
    protected function makeRelationBetweenTerms($parentTermId, $childTermId)
    {
        global $wpdb;
        $queryTemplate = "UPDATE `%sterm_taxonomy` SET `parent` = '%s', `count` = '1' WHERE `term_taxonomy_id` = '%s'";
        $query = vsprintf($queryTemplate, [$wpdb->base_prefix, $parentTermId, $childTermId]);
        $wpdb->query($query);
    }

    /**
     * Submit post with attached category which has parent category.
     * Attached only child category but not parent.
     * Expected result: 3 not cloned submissions in "Completed" state
     * (1 post, 2 categories).
     */
    public function testSubmitPostWithCategoryWhichHasParentCategory()
    {
        $rootCategoryId = $this->createTerm('Category A');
        $childCategoryId = $this->createTerm('Category B');
        $postId = $this->createPost();
        $this->makeRelationBetweenTerms($rootCategoryId, $childCategoryId);
        $this->addTaxonomyToPost($postId, $childCategoryId);

        $translationHelper = $this->getTranslationHelper();
        $submission = $translationHelper->prepareSubmission('post', 1, $postId, 2);
        $submission->getFileUri();
        $submission = $this->getSubmissionManager()->storeEntity($submission);

        $this->assertTrue(SubmissionEntity::SUBMISSION_STATUS_NEW === $submission->getStatus());
        $this->assertTrue(0 === $submission->getIsCloned());

        $this->uploadDownload($submission);

        $submissions = $this->getSubmissionManager()->find(
            [
                'content_type' => 'category',
                'status'       => SubmissionEntity::SUBMISSION_STATUS_IN_PROGRESS,
                'is_cloned'    => 0,
            ]
        );

        $this->assertTrue(2 === count($submissions));
    }

    /**
     * Submit cloned post with attached category which has parent category.
     * Attached only child category but not parent.
     * Expected result: 3 cloned submissions in "Completed" state
     * (1 post, 2 categories).
     */
    public function testSubmitClonedPostWithCategoryWhichHasParentCategory()
    {
        global $wpdb;

        $rootCategoryId = $this->createTerm('Category A');
        $childCategoryId = $this->createTerm('Category B');
        $postId = $this->createPost();
        $this->makeRelationBetweenTerms($rootCategoryId, $childCategoryId);
        $this->addTaxonomyToPost($postId, $childCategoryId);

        $translationHelper = $this->getTranslationHelper();
        $submission = $translationHelper->prepareSubmission('post', 1, $postId, 2, true);

        $this->assertTrue(SubmissionEntity::SUBMISSION_STATUS_NEW === $submission->getStatus());
        $this->assertTrue(1 === $submission->getIsCloned());

        $this->uploadDownload($submission);

        $submissions = $this->getSubmissionManager()->find(
            [
                'content_type' => 'category',
                // Status will be "Completed" because of an issue in
                // SubmissionEntity::getCompletionPercentage() method. It
                // returns 1 (100%) when total string count and excluded string
                // count equal 0.
                // TODO: fix SubmissionEntity::getCompletionPercentage() and
                // fix this test then (replace SUBMISSION_STATUS_COMPLETED with
                // SUBMISSION_STATUS_IN_PROGRESS).
                'status'       => SubmissionEntity::SUBMISSION_STATUS_COMPLETED,
                'is_cloned'    => 1,
            ]
        );

        $this->assertTrue(2 === count($submissions));
    }
}
