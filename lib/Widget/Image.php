<?php
/*
 * Xibo - Digital Signage - http://www.xibo.org.uk
 * Copyright (C) 2006-2015 Daniel Garner
 *
 * This file is part of Xibo.
 *
 * Xibo is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 *
 * Xibo is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with Xibo.  If not, see <http://www.gnu.org/licenses/>.
 */
namespace Xibo\Widget;

use Intervention\Image\ImageManagerStatic as Img;
use Xibo\Factory\MediaFactory;
use Xibo\Helper\Config;
use Xibo\Helper\Log;
use Xibo\Helper\Sanitize;

class Image extends Module
{
    /**
     * Return the Edit Form as HTML
     */
    public function EditForm()
    {

        $response = $this->getState();

        // Provide some extra form fields
        $formFields = array();

        $formFields[] = Form::AddCombo(
            'scaleTypeId',
            __('Scale Type'),
            $this->getOption('scaleType'),
            array(array('scaleTypeId' => 'center', 'scaleType' => __('Center')), array('scaleTypeId' => 'stretch', 'scaleType' => __('Stretch'))),
            'scaleTypeId',
            'scaleType',
            __('How should this image be scaled?'),
            's');

        $formFields[] = Form::AddCombo(
            'alignId',
            __('Align'),
            $this->getOption('align', 'center'),
            array(array('alignId' => 'left', 'align' => __('Left')), array('alignId' => 'center', 'align' => __('Centre')), array('alignId' => 'right', 'align' => __('Right'))),
            'alignId',
            'align',
            __('How should this image be aligned?'),
            'a', 'align-fields');

        $formFields[] = Form::AddCombo(
            'valignId',
            __('Vertical Align'),
            $this->getOption('valign', 'middle'),
            array(array('valignId' => 'top', 'valign' => __('Top')), array('valignId' => 'middle', 'valign' => __('Middle')), array('valignId' => 'bottom', 'valign' => __('Bottom'))),
            'valignId',
            'valign',
            __('How should this image be vertically aligned?'),
            'v', 'align-fields');

        // Set some field dependencies
        $response->AddFieldAction('scaleTypeId', 'init', 'center', array('.align-fields' => array('display' => 'block')));
        $response->AddFieldAction('scaleTypeId', 'change', 'center', array('.align-fields' => array('display' => 'block')));
        $response->AddFieldAction('scaleTypeId', 'init', 'center', array('.align-fields' => array('display' => 'none')), 'not');
        $response->AddFieldAction('scaleTypeId', 'change', 'center', array('.align-fields' => array('display' => 'none')), 'not');

        // Standard Edit Form
        $this->baseEditForm($formFields, $response);
    }

    public function add()
    {
        // Nothing to do for images
    }

    /**
     * Edit Media
     */
    public function edit()
    {
        // Set the properties specific to Images
        $this->setDuration(Sanitize::getInt('duration', $this->getDuration()));
        $this->setOption('name', Sanitize::getString('name', $this->getOption('name')));
        $this->setOption('scaleType', Sanitize::getString('scaleTypeId', 'center'));
        $this->setOption('align', Sanitize::getString('alignId', 'center'));
        $this->setOption('valign', Sanitize::getString('valignId', 'middle'));
    }

    /**
     * Preview code for a module
     * @param int $width
     * @param int $height
     * @param int $scaleOverride The Scale Override
     * @return string The Rendered Content
     */
    public function preview($width, $height, $scaleOverride = 0)
    {
        if ($this->module->previewEnabled == 0)
            return parent::preview($width, $height);

        $proportional = ($this->getOption('scaleType') == 'stretch') ? 'false' : 'true';
        $align = $this->getOption('align', 'center');
        $valign = $this->getOption('valign', 'middle');

        $html = '<div style="display:table; width:100%%; height: %dpx">
            <div style="text-align:%s; display: table-cell; vertical-align: %s;">
                <img src="index.php?p=content&q=getFile&mediaid=%d&width=%d&height=%d&dynamic=true&proportional=%s" />
            </div>
        </div>';

        // Show the image - scaled to the aspect ratio of this region (get from GET)
        return sprintf($html, $height, $align, $valign, $this->getMediaId(), $width, $height, $proportional);
    }

    /**
     * Get Resource
     * @param int $displayId
     * @return mixed
     */
    public function getResource($displayId = 0)
    {
        Log::debug('GetResource for %d', $this->getMediaId());

        $media = MediaFactory::getById($this->getMediaId());
        $libraryLocation = Config::GetSetting('LIBRARY_LOCATION');
        $filePath = $libraryLocation . $media->storedAs;

        // Preview or download?
        if (Sanitize::getInt('preview', 0) == 1) {

            // Preview (we output the file to the browser with image headers
            Img::configure(array('driver' => 'gd'));

            // Output a thumbnail?
            $width = Sanitize::getInt('width');
            $height = Sanitize::getInt('height');

            Log::debug('Preview Requested with Width and Height %d x %d', $width, $height);

            if ($width != 0 || $height != 0) {
                // Save a thumbnail and output it
                $thumbPath = $libraryLocation . sprintf('tn_%dx%d_%s', $width, $height, $media->storedAs);

                $eTag = md5($media->md5 . $thumbPath);

                // Create the thumbnail here
                if (!file_exists($thumbPath)) {
                    $img = Img::make($filePath)->fit($width, $height)->save($thumbPath);
                } else {
                    $img = Img::make($thumbPath);
                }
            }
            else {
                // Load the whole image
                Log::debug('Loading %s', $filePath);
                $eTag = $media->md5;
                $img = Img::make($filePath);
            }

            Log::debug('Outputting Image Response');

            // Output the file
            $this->getApp()->etag($eTag);
            $this->getApp()->expires('+1 week');
            echo $img->response();
        }
        else {
            // Download the file
            $this->download();
        }
    }

    /**
     * Is this module valid
     * @return int
     */
    public function isValid()
    {
        // Yes
        return 1;
    }
}