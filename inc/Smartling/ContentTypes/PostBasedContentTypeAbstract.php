<?php

namespace Smartling\ContentTypes;
use Smartling\Helpers\WordpressFunctionProxyHelper;

/**
 * Class PostBasedContentTypeAbstract
 * @package Smartling\ContentTypes
 */
abstract class PostBasedContentTypeAbstract extends ContentTypeAbstract
{
    /**
     * Wordpress name of content-type, e.g.: post, page, post-tag
     * @return string
     */
    public function getSystemName()
    {
        return 'post';
    }

    /**
     * Base type can be 'post' or 'term' used for Multilingual Press plugin.
     * @return string
     */
    public function getBaseType()
    {
        return 'post';
    }

    /**
     * Display name of content type, e.g.: Post
     * @return string
     */
    public function getLabel()
    {
        $result = WordpressFunctionProxyHelper::getPostTypes(['name' => $this->getSystemName()], 'objects');

        if (0 < count($result) && array_key_exists($this->getSystemName(), $result)) {
            return $result[$this->getSystemName()]->label;
        } else {
            return 'unknown';
        }
    }

    /**
     * @return array [
     *  'submissionBoard'   => true|false,
     *  'bulkSubmit'        => true|false
     * ]
     */
    public function getVisibility()
    {
        return [
            'submissionBoard' => true,
            'bulkSubmit'      => true,
        ];
    }


    /**
     * @return bool
     */
    public function isPost()
    {
        return true;
    }
}