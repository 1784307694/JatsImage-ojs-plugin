<?php

/**
 * @file plugins/generic/jatsImage/JatsImagePlugin.php
 *
 * Copyright (c) 2024 Simon Fraser University
 * Copyright (c) 2024 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class JatsImagePlugin
 *
 * @brief Replace JATS <graphic> references with OJS file URLs for XML galleys.
 */

namespace APP\plugins\generic\jatsImage;

use APP\core\Application;
use APP\core\Services;
use APP\facades\Repo;
use APP\observers\events\UsageEvent;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\submissionFile\SubmissionFile;

class JatsImagePlugin extends GenericPlugin
{
    private const XLINK_NS = 'http://www.w3.org/1999/xlink';

    /**
     * @see Plugin::register()
     *
     * @param null|mixed $mainContextId
     */
    public function register($category, $path, $mainContextId = null)
    {
        if (!parent::register($category, $path, $mainContextId)) {
            return false;
        }

        if ($this->getEnabled($mainContextId)) {
            Hook::add('ArticleHandler::download', [$this, 'articleDownloadCallback'], Hook::SEQUENCE_LATE);
        }

        return true;
    }

    /**
     * Get the display name of this plugin.
     *
     * @return string
     */
    public function getDisplayName()
    {
        return __('plugins.generic.jatsImage.displayName');
    }

    /**
     * Get a description of the plugin.
     *
     * @return string
     */
    public function getDescription()
    {
        return __('plugins.generic.jatsImage.description');
    }

    /**
     * Serve rewritten JATS XML with resolved <graphic> xlink:href URLs.
     *
     * @param string $hookName
     * @param array $args
     *
     * @return bool
     */
    public function articleDownloadCallback($hookName, $args)
    {
        $article = & $args[0];
        $galley = & $args[1];
        $fileId = & $args[2];

        if (!$galley) {
            return false;
        }

        $submissionFile = $galley->getFile();
        if (!$submissionFile || (int) $galley->getData('submissionFileId') !== (int) $fileId) {
            return false;
        }

        if (!$this->isJatsFile($submissionFile)) {
            return false;
        }

        $request = Application::get()->getRequest();
        $contents = $this->getJatsContents($request, $article, $galley, $submissionFile);
        if ($contents === null) {
            return false;
        }

        header('Content-Type: application/xml; charset=utf-8');
        echo $contents;

        $returner = true;
        Hook::call('JatsImagePlugin::articleDownloadFinished', [&$returner]);

        $publication = Repo::publication()->get($galley->getData('publicationId'));
        $issue = null;
        if ($publication && $publication->getData('issueId')) {
            $issue = Repo::issue()->get($publication->getData('issueId'));
            $issue = $issue && $issue->getJournalId() == $article->getData('contextId') ? $issue : null;
        }

        event(new UsageEvent(Application::ASSOC_TYPE_SUBMISSION_FILE, $request->getContext(), $article, $galley, $submissionFile, $issue));

        return true;
    }

    /**
     * Determine if the galley file is JATS XML.
     *
     * @param \PKP\submissionFile\SubmissionFile $submissionFile
     *
     * @return bool
     */
    private function isJatsFile($submissionFile)
    {
        $mimetype = (string) $submissionFile->getData('mimetype');
        if (in_array($mimetype, ['application/xml', 'text/xml', 'application/jats+xml'], true)) {
            return true;
        }

        $name = (string) $submissionFile->getLocalizedData('name');
        return (bool) preg_match('/\\.xml$/i', $name);
    }

    /**
     * Read JATS XML and replace graphic references with URLs for dependent files.
     *
     * @param \APP\core\Request $request
     * @param \APP\submission\Submission $article
     * @param \PKP\galley\Galley $galley
     * @param \PKP\submissionFile\SubmissionFile $submissionFile
     *
     * @return string|null
     */
    private function getJatsContents($request, $article, $galley, $submissionFile)
    {
        $contents = Services::get('file')->fs->read($submissionFile->getData('path'));
        if ($contents === null) {
            return null;
        }

        $doc = new \DOMDocument('1.0', 'utf-8');
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput = false;
        $prev = libxml_use_internal_errors(true);
        $loaded = $doc->loadXML($contents, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if (!$loaded) {
            return $contents;
        }

        $urlByName = $this->getEmbeddableFileUrlMap($request, $article, $galley, $submissionFile);
        if (empty($urlByName)) {
            return $contents;
        }

        $nodes = [];
        foreach (['graphic', 'inline-graphic'] as $tagName) {
            foreach ($doc->getElementsByTagName($tagName) as $node) {
                $nodes[] = $node;
            }
        }

        foreach ($nodes as $node) {
            $href = $this->getGraphicHref($node);
            if ($href === '') {
                continue;
            }
            $fileUrl = $this->resolveGraphicUrl($href, $urlByName);
            if (!$fileUrl) {
                continue;
            }
            $this->setGraphicHref($node, $fileUrl);
        }

        return $doc->saveXML();
    }

    /**
     * Build a lookup map of dependent file names to download URLs.
     *
     * @param \APP\core\Request $request
     * @param \APP\submission\Submission $article
     * @param \PKP\galley\Galley $galley
     * @param \PKP\submissionFile\SubmissionFile $submissionFile
     *
     * @return array
     */
    private function getEmbeddableFileUrlMap($request, $article, $galley, $submissionFile)
    {
        $embeddableFiles = Repo::submissionFile()
            ->getCollector()
            ->filterByAssoc(
                Application::ASSOC_TYPE_SUBMISSION_FILE,
                [$submissionFile->getId()]
            )
            ->filterByFileStages([SubmissionFile::SUBMISSION_FILE_DEPENDENT])
            ->includeDependentFiles()
            ->getMany();

        $urlByName = [];

        foreach ($embeddableFiles as $embeddableFile) {
            $params = [];
            $mimetype = $embeddableFile->getData('mimetype');
            if ($mimetype === 'text/plain' || $mimetype === 'text/css') {
                $params['inline'] = 'true';
            }

            $fileUrl = $request->url(
                null,
                'article',
                'download',
                [
                    $article->getBestId(),
                    'version',
                    $galley->getData('publicationId'),
                    $galley->getBestGalleyId(),
                    $embeddableFile->getId(),
                    $embeddableFile->getLocalizedData('name'),
                ],
                $params
            );

            $this->addNameMapping($urlByName, $embeddableFile->getLocalizedData('name'), $fileUrl);
        }

        return $urlByName;
    }

    /**
     * Add multiple filename variants for URL lookup.
     *
     * @param array $urlByName
     * @param string $name
     * @param string $url
     */
    private function addNameMapping(&$urlByName, $name, $url)
    {
        if (!$name) {
            return;
        }

        $candidates = [];
        $candidates[] = $name;
        $candidates[] = rawurlencode($name);

        $base = basename($name);
        $candidates[] = $base;
        $candidates[] = rawurlencode($base);

        foreach ($candidates as $candidate) {
            $urlByName[$candidate] = $url;
            $urlByName[strtolower($candidate)] = $url;
        }
    }

    /**
     * Read the graphic href attribute from a node.
     *
     * @param \DOMElement $node
     *
     * @return string
     */
    private function getGraphicHref($node)
    {
        if ($node->hasAttributeNS(self::XLINK_NS, 'href')) {
            return (string) $node->getAttributeNS(self::XLINK_NS, 'href');
        }
        if ($node->hasAttribute('xlink:href')) {
            return (string) $node->getAttribute('xlink:href');
        }
        if ($node->hasAttribute('href')) {
            return (string) $node->getAttribute('href');
        }
        return '';
    }

    /**
     * Set the graphic href attribute on a node.
     *
     * @param \DOMElement $node
     * @param string $url
     */
    private function setGraphicHref($node, $url)
    {
        if ($node->hasAttributeNS(self::XLINK_NS, 'href') || $node->getAttributeNS(self::XLINK_NS, 'href') !== '') {
            $node->setAttributeNS(self::XLINK_NS, 'xlink:href', $url);
            return;
        }
        if ($node->hasAttribute('xlink:href')) {
            $node->setAttribute('xlink:href', $url);
            return;
        }
        $node->setAttribute('href', $url);
    }

    /**
     * Resolve a JATS graphic reference to an OJS file URL.
     *
     * @param string $href
     * @param array $urlByName
     *
     * @return string|null
     */
    private function resolveGraphicUrl($href, $urlByName)
    {
        $decoded = rawurldecode($href);
        $candidates = [
            $href,
            $decoded,
            basename($href),
            basename($decoded),
        ];

        foreach ($candidates as $candidate) {
            if (isset($urlByName[$candidate])) {
                return $urlByName[$candidate];
            }
            $lower = strtolower($candidate);
            if (isset($urlByName[$lower])) {
                return $urlByName[$lower];
            }
        }

        return null;
    }
}

if (!PKP_STRICT_MODE) {
    class_alias('\APP\plugins\generic\jatsImage\JatsImagePlugin', '\JatsImagePlugin');
}
