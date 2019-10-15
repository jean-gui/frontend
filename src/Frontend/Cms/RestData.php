<?php
declare(strict_types=1);

namespace Studio24\Frontend\Cms;

use GuzzleHttp\Client;
use Studio24\Frontend\Api\Provider\RestApiProvider;
use Studio24\Frontend\Content\ContentInterface;
use Studio24\Frontend\Content\Field\ArrayContent;
use Studio24\Frontend\Content\Field\AssetField;
use Studio24\Frontend\Content\Field\Audio;
use Studio24\Frontend\Content\Field\ContentField;
use Studio24\Frontend\Content\Field\ContentFieldCollection;
use Studio24\Frontend\Content\Field\ContentFieldInterface;
use Studio24\Frontend\Content\Field\Number;
use Studio24\Frontend\Content\Field\Document;
use Studio24\Frontend\Content\Field\Video;
use Studio24\Frontend\Content\Metadata;
use Studio24\Frontend\ContentModel\ContentFieldCollectionInterface;
use Studio24\Frontend\ContentModel\Field;
use Studio24\Frontend\Exception\ApiException;
use Studio24\Frontend\Exception\ContentFieldNotSetException;
use Studio24\Frontend\Exception\ContentTypeNotSetException;
use Studio24\Frontend\Content\BaseContent;
use Studio24\Frontend\Content\Field\Boolean;
use Studio24\Frontend\Content\Field\Component;
use Studio24\Frontend\Content\Field\Date;
use Studio24\Frontend\Content\Field\DateTime;
use Studio24\Frontend\Content\Field\FlexibleContent;
use Studio24\Frontend\Content\Field\Image;
use Studio24\Frontend\Content\Field\PlainText;
use Studio24\Frontend\Content\Field\Relation;
use Studio24\Frontend\Content\Field\RichText;
use Studio24\Frontend\Content\Field\ShortText;
use Studio24\Frontend\Content\Page;
use Studio24\Frontend\Content\PageCollection;
use Studio24\Frontend\ContentModel\ContentModel;
use Studio24\Frontend\ContentModel\ContentType;
use Studio24\Frontend\ContentModel\FieldInterface;
use Studio24\Frontend\Exception\ContentFieldException;

class RestData extends ContentRepository
{
    /**
     * API
     *
     * @var RestApiProvider
     */
    protected $api;

    /**
     * Constructor
     *
     * @param string $baseUrl API base URI
     * @param ContentModel $contentModel Content model
     */
    public function __construct(string $baseUrl = '', ContentModel $contentModel = null)
    {
        $this->api = new RestApiProvider($baseUrl);

        if ($contentModel instanceof ContentModel) {
            $this->setContentModel($contentModel);
        }
    }

    /**
     * Set HTTP client
     *
     * Useful for testing
     *
     * @param Client $client
     * @return RestData Fluent interface
     */
    public function setClient(Client $client): RestData
    {
        $this->api->setClient($client);

        return $this;
    }

    /**
     * Return the content type API endpoint
     *
     * @return string
     * @throws ContentTypeNotSetException
     */
    public function getContentApiEndpoint(): string
    {
        if (!$this->hasContentType()) {
            throw new ContentTypeNotSetException('Content type is not set!');
        }

        return $this->getContentType()->getApiEndpoint();
    }

    /**
     * Return list of content items
     *
     * @param int $page Page number, default = 1
     * @param array $options Array of options to select data from WordPress
     * @return PageCollection;
     * @throws ContentTypeNotSetException
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Studio24\Frontend\Exception\ContentFieldException
     * @throws \Studio24\Frontend\Exception\FailedRequestException
     * @throws \Studio24\Frontend\Exception\PermissionException
     * @throws \Studio24\Frontend\Exception\PaginationException
     */
    public function list(int $page = 1, array $options = []): PageCollection
    {
        $cacheKey = $this->getCacheKey($this->getContentType()->getName(), 'list', $page, extract($options));

        if ($this->hasCache()) {
            $data = $this->cache->get($cacheKey, false);
            if ($data !== false) {
                return $data;
            }
        }

        $list = $this->api->list(
            $this->getContentApiEndpoint(),
            $page,
            $options
        );
        $pages = new PageCollection($list->getPagination());

        foreach ($list->getResponseData() as $pageData) {
            $pages->addItem($this->createPage($pageData));
        }

        // Any metadata?
        $metadata = $pages->getMetadata();
        $ignoreMetadata = ['total_results', 'limit', 'results', 'page'];
        foreach ($list->getMetadata() as $key => $value) {
            if (!in_array($key, $ignoreMetadata)) {
                $metadata->add($key, $value);
            }
        }

        if ($this->hasCache()) {
            $this->cache->set($cacheKey, $pages, $this->getCacheLifetime(), $this->getCacheLifetime());
        }

        return $pages;
    }

    /**
     * Return a single item
     *
     * @param mixed $id Identifier value
     * @param string $contentType
     * @return Page
     * @throws ContentFieldNotSetException
     * @throws ContentTypeNotSetException
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Studio24\Frontend\Exception\ContentFieldException
     * @throws \Studio24\Frontend\Exception\FailedRequestException
     * @throws \Studio24\Frontend\Exception\PermissionException
     */
    public function getOne($id): Page
    {
        $cacheKey = $this->getCacheKey($this->getContentType()->getName(), $id);

        if ($this->hasCache()) {
            $data = $this->cache->get($cacheKey, false);
            if ($data !== false) {
                return $data;
            }
        }

        // Get content
        $data = $this->api->getOne($this->getContentApiEndpoint(), $id);
        $page = $this->createPage($data);

        if ($this->hasCache()) {
            $this->cache->set($cacheKey, $page, $this->getCacheLifetime(), $this->getCacheLifetime());
        }

        return $page;
    }


    /**
     * Generate page object from API data
     *
     * @param array $data
     * @return Page
     * @throws ContentFieldNotSetException
     * @throws ContentTypeNotSetException
     * @throws \Studio24\Frontend\Exception\ContentFieldException
     */
    public function createPage(array $data): Page
    {
        $page = new Page();
        $page->setContentType($this->getContentType());
        $this->setCustomContentFields($this->getContentType(), $page, $data);

        return $page;
    }


    /**
     * @todo Don't really need this - review abstract class
     *
     * @param BaseContent $page
     * @param array $data
     * @return void|null
     */
    public function setContentFields(BaseContent $page, array $data)
    {
        return;
    }


    /**
     * Build up custom content fields from content model definition
     *
     * @param ContentType $contentType
     * @param ContentInterface $content
     * @param array $data
     * @return BaseContent
     * @throws ContentFieldNotSetException
     * @throws ContentTypeNotSetException
     * @throws \Studio24\Frontend\Exception\ContentFieldException
     */
    public function setCustomContentFields(ContentType $contentType, ContentInterface $content, array $data): ContentInterface
    {
        foreach ($contentType as $contentField) {
            $name = $contentField->getName();
            if (!isset($data[$name])) {
                continue;
            }

            $value = $data[$name];
            $contentField = $this->getContentField($contentField, $value);
            if ($contentField !== null) {
                $content->addContent($contentField);
            }
        }

        return $content;
    }

    /**
     * Return a content field populated with passed data
     *
     * @param FieldInterface $field Content field definition
     * @param mixed $value Content field value
     * @return ContentFieldInterface Populated content field object, or null on failure
     * @throws ContentTypeNotSetException
     * @throws ContentFieldException
     * @throws ContentFieldNotSetException
     */
    public function getContentField(FieldInterface $field, $value): ?ContentFieldInterface
    {
        try {
            $name = $field->getName();
            switch ($field->getType()) {
                case 'number':
                    return new Number($name, $value);
                    break;

                case 'text':
                    return new ShortText($name, $value);
                    break;

                case 'plaintext':
                    return new PlainText($name, $value);
                    break;

                case 'richtext':
                    return new RichText($name, $value);
                    break;

                case 'date':
                    return new Date($name, $value);
                    break;

                case 'datetime':
                    return new DateTime($name, $value);
                    break;

                case 'boolean':
                    return new Boolean($name, $value);
                    break;

                case 'array':
                    $array = new ArrayContent($name);

                    if (!is_array($value)) {
                        break;
                    }

                    // Loop through data array
                    foreach ($value as $row) {
                        // For each row add a set of content fields
                        $item = new ContentFieldCollection();
                        foreach ($field as $childField) {
                            if (!isset($row[$childField->getName()])) {
                                continue;
                            }
                            $childValue = $row[$childField->getName()];
                            $contentField = $this->getContentField($childField, $childValue);
                            if ($contentField !== null) {
                                $item->addItem($this->getContentField($childField, $childValue));
                            }
                        }
                        $array->addItem($item);
                    }

                    return $array;
                    break;
            }
        } catch (\Error $e) {
            $message = sprintf("Fatal error when creating content field '%s' (type: %s) for value: %s", $field->getName(), $field->getType(), print_r($value, true));
            throw new ContentFieldException($message, 0, $e);
        } catch (\Exception $e) {
            $message = sprintf("Exception thrown when creating content field '%s' (type: %s) for value: %s", $field->getName(), $field->getType(), print_r($value, true));
            throw new ContentFieldException($message, 0, $e);
        }

        return null;
    }
}
