<?php
/*
 * Xibo - Digital Signage - http://www.xibo.org.uk
 * Copyright (C) 2015 Spring Signage Ltd
 *
 * This file (CampaignFactory.php) is part of Xibo.
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


namespace Xibo\Factory;

use Xibo\Entity\Campaign;
use Xibo\Entity\User;
use Xibo\Exception\NotFoundException;
use Xibo\Service\LogServiceInterface;
use Xibo\Service\SanitizerServiceInterface;
use Xibo\Storage\StorageServiceInterface;

/**
 * Class CampaignFactory
 * @package Xibo\Factory
 */
class CampaignFactory extends BaseFactory
{
    /**
     * @var PermissionFactory
     */
    private $permissionFactory;

    /**
     * @var ScheduleFactory
     */
    private $scheduleFactory;

    /**
     * @var DisplayFactory
     */
    private $displayFactory;

    /**
     * Construct a factory
     * @param StorageServiceInterface $store
     * @param LogServiceInterface $log
     * @param SanitizerServiceInterface $sanitizerService
     * @param User $user
     * @param UserFactory $userFactory
     * @param PermissionFactory $permissionFactory
     * @param ScheduleFactory $scheduleFactory
     * @param DisplayFactory $displayFactory
     */
    public function __construct($store, $log, $sanitizerService, $user, $userFactory, $permissionFactory, $scheduleFactory, $displayFactory)
    {
        $this->setCommonDependencies($store, $log, $sanitizerService);
        $this->setAclDependencies($user, $userFactory);
        $this->permissionFactory = $permissionFactory;
        $this->scheduleFactory = $scheduleFactory;
        $this->displayFactory = $displayFactory;
    }

    /**
     * @return Campaign
     */
    public function createEmpty()
    {
        return new Campaign($this->getStore(), $this->getLog(), $this->permissionFactory, $this->scheduleFactory, $this->displayFactory);
    }

    /**
     * Create Campaign
     * @param string $name
     * @param int $userId
     * @return Campaign
     */
    public function create($name, $userId)
    {
        $campaign = $this->createEmpty();
        $campaign->ownerId = $userId;
        $campaign->campaign = $name;

        return $campaign;
    }

    /**
     * Get Campaign by ID
     * @param int $campaignId
     * @return Campaign
     * @throws NotFoundException
     */
    public function getById($campaignId)
    {
        $this->getLog()->debug('CampaignFactory getById(%d)', $campaignId);

        $campaigns = $this->query(null, array('disableUserCheck' => 1, 'campaignId' => $campaignId, 'isLayoutSpecific' => -1, 'excludeTemplates' => -1));

        if (count($campaigns) <= 0) {
            $this->getLog()->debug('Campaign not found with ID %d', $campaignId);
            throw new NotFoundException(\__('Campaign not found'));
        }

        // Set our layout
        return $campaigns[0];
    }

    /**
     * Get Campaign by Owner Id
     * @param int $ownerId
     * @return array[Campaign]
     */
    public function getByOwnerId($ownerId)
    {
        return $this->query(null, array('ownerId' => $ownerId, 'excludeTemplates' => -1));
    }

    /**
     * Get Campaign by Layout
     * @param int $layoutId
     * @return array[Campaign]
     */
    public function getByLayoutId($layoutId)
    {
        return $this->query(null, array('disableUserCheck' => 1, 'layoutId' => $layoutId, 'excludeTemplates' => -1));
    }

    /**
     * Query Campaigns
     * @param array $sortOrder
     * @param array $filterBy
     * @return array[Campaign]
     */
    public function query($sortOrder = null, $filterBy = array(), $options = array())
    {
        if ($sortOrder == null)
            $sortOrder = array('campaign');

        $campaigns = array();
        $params = array();

        $select = '
        SELECT `campaign`.campaignId, `campaign`.campaign, `campaign`.isLayoutSpecific, `campaign`.userId AS ownerId,
              (
                SELECT COUNT(*)
                  FROM lkcampaignlayout
                 WHERE lkcampaignlayout.campaignId = `campaign`.campaignId
              ) AS numberLayouts
        ';

        $body  = '
            FROM `campaign`
              LEFT OUTER JOIN `lkcampaignlayout`
              ON lkcampaignlayout.CampaignID = campaign.CampaignID
              LEFT OUTER JOIN `layout`
              ON lkcampaignlayout.LayoutID = layout.LayoutID
           WHERE 1 = 1
        ';

        // View Permissions
        $this->viewPermissionSql('Xibo\Entity\Campaign', $body, $params, '`campaign`.campaignId', '`campaign`.userId', $filterBy);

        if ($this->getSanitizer()->getString('isLayoutSpecific', 0, $filterBy) != -1) {
            // Exclude layout specific campaigns
            $body .= " AND `campaign`.isLayoutSpecific = :isLayoutSpecific ";
            $params['isLayoutSpecific'] = $this->getSanitizer()->getString('isLayoutSpecific', 0, $filterBy);
        }

        if ($this->getSanitizer()->getString('campaignId', 0, $filterBy) != 0) {
            // Join Campaign back onto it again
            $body .= " AND `campaign`.campaignId = :campaignId ";
            $params['campaignId'] = $this->getSanitizer()->getString('campaignId', 0, $filterBy);
        }

        if ($this->getSanitizer()->getString('ownerId', 0, $filterBy) != 0) {
            // Join Campaign back onto it again
            $body .= " AND `campaign`.userId = :ownerId ";
            $params['ownerId'] = $this->getSanitizer()->getString('ownerId', 0, $filterBy);
        }

        if ($this->getSanitizer()->getString('layoutId', 0, $filterBy) != 0) {
            // Filter by Layout
            $body .= " AND `lkcampaignlayout`.layoutId = :layoutId ";
            $params['layoutId'] = $this->getSanitizer()->getString('layoutId', 0, $filterBy);
        }

        if ($this->getSanitizer()->getString('name', $filterBy) != '') {
            // convert into a space delimited array
            $names = explode(' ', $this->getSanitizer()->getString('name', $filterBy));

            $i = 0;
            foreach($names as $searchName) {
                $i++;

                // Not like, or like?
                if (substr($searchName, 0, 1) == '-') {
                    $body .= " AND campaign.Campaign NOT LIKE :search$i ";
                    $params['search' . $i] = '%' . ltrim($searchName) . '%';
                }
                else {
                    $body .= " AND campaign.Campaign LIKE :search$i ";
                    $params['search' . $i] = '%' . ltrim($searchName) . '%';
                }
            }
        }

        // Exclude templates by default
        if ($this->getSanitizer()->getInt('excludeTemplates', 1, $filterBy) != -1) {
            if ($this->getSanitizer()->getInt('excludeTemplates', 1, $filterBy) == 1) {
                $body .= " AND `campaign`.campaignId NOT IN (SELECT `campaignId` FROM `lkcampaignlayout` WHERE layoutId IN (SELECT layoutId FROM lktaglayout WHERE tagId = 1)) ";
            } else {
                $body .= " AND `campaign`.campaignId IN (SELECT `campaignId` FROM `lkcampaignlayout` WHERE layoutId IN (SELECT layoutId FROM lktaglayout WHERE tagId = 1)) ";
            }
        }

        $group = 'GROUP BY `campaign`.CampaignID, Campaign, IsLayoutSpecific, `campaign`.userId ';

        // Sorting?
        $order = '';
        if (is_array($sortOrder))
            $order .= 'ORDER BY ' . implode(',', $sortOrder);

        $limit = '';
        // Paging
        if ($filterBy !== null && $this->getSanitizer()->getInt('start', $filterBy) !== null && $this->getSanitizer()->getInt('length', $filterBy) !== null) {
            $limit = ' LIMIT ' . intval($this->getSanitizer()->getInt('start', $filterBy), 0) . ', ' . $this->getSanitizer()->getInt('length', 10, $filterBy);
        }

        $intProperties = ['intProperties' => ['numberLayouts']];

        // Layout durations
        if ($this->getSanitizer()->getInt('totalDuration', 0, $options) != 0) {
            $select .= ", SUM(`layout`.duration) AS totalDuration";
            $intProperties = ['intProperties' => ['numberLayouts', 'totalDuration']];
        }

        $sql = $select . $body . $group . $order . $limit;


        foreach ($this->getStore()->select($sql, $params) as $row) {
            $campaigns[] = $this->createEmpty()->hydrate($row, $intProperties);
        }

        // Paging
        if ($limit != '' && count($campaigns) > 0) {
            $results = $this->getStore()->select('SELECT COUNT(DISTINCT campaign.campaignId) AS total ' . $body, $params);
            $this->_countLast = intval($results[0]['total']);
        }

        return $campaigns;
    }

}
