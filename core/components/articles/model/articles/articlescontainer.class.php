<?php
/**
 * Articles
 *
 * Copyright 2011-12 by Shaun McCormick <shaun+articles@modx.com>
 *
 * Articles is free software; you can redistribute it and/or modify it under the
 * terms of the GNU General Public License as published by the Free Software
 * Foundation; either version 2 of the License, or (at your option) any later
 * version.
 *
 * Articles is distributed in the hope that it will be useful, but WITHOUT ANY
 * WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR
 * A PARTICULAR PURPOSE. See the GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along with
 * Articles; if not, write to the Free Software Foundation, Inc., 59 Temple
 * Place, Suite 330, Boston, MA 02111-1307 USA
 *
 * @package articles
 */
require_once MODX_CORE_PATH.'model/modx/modprocessor.class.php';
require_once MODX_CORE_PATH.'model/modx/processors/resource/create.class.php';
require_once MODX_CORE_PATH.'model/modx/processors/resource/update.class.php';
/**
 * @package articles
 */
class ArticlesContainer extends modResource {
    /** @var modX $xpdo */
    public $xpdo;

    public $showInContextMenu = true;
    /**
     * Override modResource::__construct to ensure a few specific fields are forced to be set.
     * @param xPDO $xpdo
     */
    function __construct(xPDO & $xpdo) {
        parent :: __construct($xpdo);
        $this->set('class_key','Articles');
        $this->set('hide_children_in_tree',true);
    }

    /**
     * Get the controller path for our Articles type.
     * 
     * {@inheritDoc}
     * @static
     * @param xPDO $modx
     * @return string
     */
    public static function getControllerPath(xPDO &$modx) {
        return $modx->getOption('articles.core_path',null,$modx->getOption('core_path').'components/articles/').'controllers/container/';
    }

    /**
     * Provide the custom context menu for Articles.
     * {@inheritDoc}
     * @return array
     */
    public function getContextMenuText() {
        $this->xpdo->lexicon->load('articles:default');
        return array(
            'text_create' => $this->xpdo->lexicon('articles.container'),
            'text_create_here' => $this->xpdo->lexicon('articles.container_create_here'),
        );
    }

    /**
     * Provide the name of this CRT.
     * {@inheritDoc}
     * @return string
     */
    public function getResourceTypeName() {
        $this->xpdo->lexicon->load('articles:default');
        return $this->xpdo->lexicon('articles.container');
    }

    /**
     * Prevent isLazy error since Articles types have extra DB fields
     * @param string $key
     * @return bool
     */
    public function isLazy($key = '') {
        return false;
    }

    /**
     * Override modResource::process to set some custom placeholders for the Resource when rendering it in the front-end.
     * {@inheritDoc}
     * @return string
     */
    public function process() {
        if ($this->isRss()) {
            $this->set('template',0);
            $this->set('contentType','application/rss+xml');
            /** @var modContentType $contentType */
            $contentType = $this->xpdo->getObject('modContentType',array('mime_type' => 'application/rss+xml'));
            if ($contentType) {
                $this->set('content_type',$contentType->get('id'));
            }
            $this->_content= $this->getRssCall();
            $maxIterations= intval($this->xpdo->getOption('parser_max_iterations',10));
            $this->xpdo->parser->processElementTags('', $this->_content, false, false, '[[', ']]', array(), $maxIterations);
            $this->_processed= true;
            $this->set('cacheable',false);
        } else {
            $this->xpdo->lexicon->load('articles:frontend');
            $this->getPostListingCall();
            $this->getArchivistCall();
            $this->getTagListerCall();
            $this->getLatestPostsCall();
            $settings = $this->getContainerSettings();
            if ($this->getOption('commentsEnabled',$settings,true)) {
                $this->getLatestCommentsCall();
                $this->xpdo->setPlaceholder('comments_enabled',1);
            } else {
                $this->xpdo->setPlaceholder('comments_enabled',0);
            }
            $this->_content = parent::process();
        }
        return $this->_content;
    }

    /**
     * Check to see if the request is asking for an RSS feed
     * @return boolean
     */
    public function isRss() {
        $isRss = false;
        $settings = $this->getContainerSettings();
        $feedAppendage = $this->xpdo->getOption('rssAlias',$settings,'feed.rss,rss');
        $feedAppendage = explode(',',$feedAppendage);
        $fullUri = $this->xpdo->context->getOption('base_url',null,MODX_BASE_URL).$this->get('uri');
        if (strpos($_SERVER['REQUEST_URI'],$fullUri) === 0 && strlen($fullUri) != strlen($_SERVER['REQUEST_URI'])) {
            $appendage = rtrim(str_replace($fullUri,'',$_SERVER['REQUEST_URI']),'/');
            if (in_array($appendage,$feedAppendage)) {
                $isRss = true;
            }
        }
        return $isRss;
    }

    /**
     * Get the call for the RSS feed
     * @return string
     */
    public function getRssCall() {
        $settings = $this->getContainerSettings();
        $content = '[[!getResources?
          &cache=`0`
          &pageVarKey=`page`
          &parents=`[[*id]]`
          &where=`{"class_key":"Article","searchable":1}`
          &limit=`'.$this->xpdo->getOption('rssItems',$settings,10).'`
          &showHidden=`1`
          &includeContent=`1`
          &includeTVs=`1`
          &tpl=`'.$this->xpdo->getOption('tplRssItem',$settings,'sample.ArticlesRssItem').'`
        ]]';
        $content = $this->xpdo->getChunk($this->xpdo->getOption('tplRssFeed',$settings,'sample.ArticlesRss'),array(
            'content' => $content,
            'year' => date('Y'),
        ));
        return $content;
    }
    /**
     * Get the getPage and getArchives call to display listings of posts on the container.
     * @return void
     */
    public function getPostListingCall() {
        $settings = $this->getContainerSettings();
        $where = array('class_key' => 'Article');
        if (!empty($_REQUEST['arc_user'])) {
            $userPk = $this->xpdo->sanitizeString($_REQUEST['arc_user']);
            if (intval($userPk) == 0) {
                /** @var modUser $user */
                $user = $this->xpdo->getObject('modUser',array('username' => $userPk));
                if ($user) {
                    $userPk = $user->get('id');
                } else { $userPk = false; }
            }
            if ($userPk !== false) {
                $where['createdby:='] = $userPk;
                $this->set('cacheable',false);
            }
        }

        $output = '[[!getArchives?
          &elementClass=`modSnippet`
          &element=`getArchives`
          &cache=`0`
          &pageVarKey=`page`
          &parents=`[[*id]]`
          &where=`'.$this->xpdo->toJSON($where).'`
          &limit=`'.$this->xpdo->getOption('articlesPerPage',$settings,10).'`
          &showHidden=`1`
          &includeContent=`1`
          &includeTVs=`1`
          &tagKey=`articlestags`
          &tagSearchType=`contains`
          &tpl=`'.$this->xpdo->getOption('tplArticleRow',$settings,'sample.ArticleRowTpl').'`
        ]]';
        $this->xpdo->setPlaceholder('articles',$output);

        $this->xpdo->setPlaceholder('paging','[[!+page.nav:notempty=`
<div class="paging">
<ul class="pageList">
  [[!+page.nav]]
</ul>
</div>
`]]');
    }

    /**
     * Get the Archivist call for displaying archives in month/year format.
     * @return void
     */
    public function getArchivistCall() {
        $settings = $this->getContainerSettings();
        $output = '[[Archivist?
            &tpl=`'.$this->xpdo->getOption('tplArchiveMonth',$settings,'row').'`
            &target=`'.$this->get('id').'`
            &parents=`'.$this->get('id').'`
            &depth=`4`
            &limit=`'.$this->xpdo->getOption('archiveListingsLimit',$settings,10).'`
            &useMonth=`'.$this->xpdo->getOption('archiveByMonth',$settings,1).'`
            &useFurls=`'.$this->xpdo->getOption('archiveWithFurls',$settings,1).'`
            &cls=`'.$this->xpdo->getOption('archiveCls',$settings,'').'`
            &altCls=`'.$this->xpdo->getOption('archiveAltCls',$settings,'').'`
            &setLocale=`1`
        ]]';
        $this->xpdo->setPlaceholder('archives',$output);
    }

    /**
     * Get the tagLister call for displaying tag listings on the front-end.
     * @return string
     */
    public function getTagListerCall() {
        $settings = $this->getContainerSettings();
        $output = '[[tagLister?
            &tpl=`'.$this->xpdo->getOption('tplTagRow',$settings,'tag').'`
            &tv=`articlestags`
            &parents=`'.$this->get('id').'`
            &tvDelimiter=`,`
            &useTagFurl=`1`
            &limit=`'.$this->xpdo->getOption('tagsLimit',$settings,10).'`
            &cls=`'.$this->xpdo->getOption('tagsCls',$settings,'tl-tag').'`
            &altCls=`'.$this->xpdo->getOption('tagsAltCls',$settings,'tl-tag-alt').'`
            &target=`'.$this->get('id').'`
        ]]';
        $this->xpdo->setPlaceholder('tags',$output);
        return $output;
    }

    /**
     * Get the call for the latest posts
     * @return string
     */
    public function getLatestPostsCall() {
        $settings = $this->getContainerSettings();
        $output = '[[getResources?
            &parents=`'.$this->get('id').'`
            &hideContainers=`1`
            &showHidden=`1`
            &tpl=`'.$this->xpdo->getOption('latestPostsTpl',$settings,'sample.ArticlesLatestPostTpl').'`
            &limit=`'.$this->xpdo->getOption('latestPostsLimit',$settings,5).'`
            &sortby=`publishedon`
            &where=`{"class_key":"Article"}`
        ]]';
        $this->xpdo->setPlaceholder('latest_posts',$output);
        return $output;
    }

    /**
     * Get the call for the latest comments
     * @return string
     */
    public function getLatestCommentsCall() {
        $settings = $this->getContainerSettings();
        $output = '[[!QuipLatestComments?
            &type=`family`
            &family=`b'.$this->get('id').'`
            &tpl=`'.$this->xpdo->getOption('latestCommentsTpl',$settings,'quipLatestComment').'`
            &limit=`'.$this->xpdo->getOption('latestCommentsLimit',$settings,10).'`
            &bodyLimit=`'.$this->xpdo->getOption('latestCommentsBodyLimit',$settings,300).'`
            &rowCss=`'.$this->xpdo->getOption('latestCommentsRowCss',$settings,'quip-latest-comment').'`
            &altRowCss=`'.$this->xpdo->getOption('latestCommentsAltRowCss',$settings,'quip-latest-comment-alt').'`
        ]]';
        $this->xpdo->setPlaceholder('latest_comments',$output);
        return $output;
    }

    /**
     * Get an array of settings for the container.
     * @return array
     */
    public function getContainerSettings() {
        $settings = $this->get('articles_container_settings');
        $this->xpdo->setDebug(false);
        if (!empty($settings)) {
            $settings = is_array($settings) ? $settings : $this->xpdo->fromJSON($settings);
        }
        return !empty($settings) ? $settings : array();
    }
}

/**
 * Overrides the modResourceCreateProcessor to provide custom processor functionality for the Articles type
 *
 * @package articles
 */
class ArticlesContainerCreateProcessor extends modResourceCreateProcessor {
    /**
     * Override modResourceCreateProcessor::afterSave to provide custom functionality, saving the container settings to a
     * custom field in the manager
     * {@inheritDoc}
     * @return boolean
     */
    public function beforeSave() {
        $properties = $this->getProperties();
        $settings = $this->object->get('articles_container_settings');
        foreach ($properties as $k => $v) {
            if (substr($k,0,8) == 'setting_') {
                $key = substr($k,8);
                if ($v === 'false') $v = 0;
                if ($v === 'true') $v = 1;
                $settings[$key] = $v;
            }
        }
        $this->object->set('articles_container_settings',$settings);

        $this->object->set('class_key','ArticlesContainer');
        $this->object->set('cacheable',true);
        $this->object->set('isfolder',true);
        return parent::beforeSave();
    }

    /**
     * Override modResourceCreateProcessor::afterSave to provide custom functionality
     * {@inheritDoc}
     * @return boolean
     */
    public function afterSave() {
        $this->addContainerId();
        $this->setProperty('clearCache',true);
        return parent::afterSave();
    }

    /**
     * Add the Container ID to the articles system setting for managing container IDs for FURL redirection.
     * @return boolean
     */
    public function addContainerId() {
        $saved = true;
        /** @var modSystemSetting $setting */
        $setting = $this->modx->getObject('modSystemSetting',array('key' => 'articles.container_ids'));
        if (!$setting) {
            $setting = $this->modx->newObject('modSystemSetting');
            $setting->set('key','articles.container_ids');
            $setting->set('namespace','articles');
            $setting->set('area','furls');
            $setting->set('xtype','textfield');
        }
        $value = $setting->get('value');
        $archiveKey = $this->object->get('id').':arc_';
        $value = is_array($value) ? $value : explode(',',$value);
        if (!in_array($archiveKey,$value)) {
            $value[] = $archiveKey;
            $value = array_unique($value);
            $setting->set('value',implode(',',$value));
            $saved = $setting->save();
        }
        return $saved;
    }
}

/**
 * Overrides the modResourceUpdateProcessor to provide custom processor functionality for the Articles type
 *
 * @package articles
 */
class ArticlesContainerUpdateProcessor extends modResourceUpdateProcessor {
    /**
     * Override modResourceUpdateProcessor::beforeSave to provide custom functionality, saving settings for the container
     * to a custom field in the DB
     * {@inheritDoc}
     * @return boolean
     */
    public function beforeSave() {
        $properties = $this->getProperties();
        $settings = $this->object->get('articles_container_settings');
        foreach ($properties as $k => $v) {
            if (substr($k,0,8) == 'setting_') {
                $key = substr($k,8);
                if ($v === 'false') $v = 0;
                if ($v === 'true') $v = 1;
                $settings[$key] = $v;
            }
        }
        $this->object->set('articles_container_settings',$settings);
        return parent::beforeSave();
    }

    /**
     * Override modResourceUpdateProcessor::afterSave to provide custom functionality
     * {@inheritDoc}
     * @return boolean
     */
    public function afterSave() {
        $this->addContainerId();
        $this->setProperty('clearCache',true);
        $this->object->set('isfolder',true);
        return parent::afterSave();
    }

    /**
     * Add the Container ID to the articles system setting for managing IDs for FURL redirection.
     * @return boolean
     */
    public function addContainerId() {
        $saved = true;
        /** @var modSystemSetting $setting */
        $setting = $this->modx->getObject('modSystemSetting',array('key' => 'articles.container_ids'));
        if (!$setting) {
            $setting = $this->modx->newObject('modSystemSetting');
            $setting->set('key','articles.container_ids');
            $setting->set('namespace','articles');
            $setting->set('area','furls');
            $setting->set('xtype','textfield');
        }
        $value = $setting->get('value');
        $archiveKey = $this->object->get('id').':arc_';
        $value = is_array($value) ? $value : explode(',',$value);
        if (!in_array($archiveKey,$value)) {
            $value[] = $archiveKey;
            $value = array_unique($value);
            $setting->set('value',implode(',',$value));
            $saved = $setting->save();
        }
        return $saved;
    }
}