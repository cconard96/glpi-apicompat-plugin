<?php

/**
 * SPDX-FileCopyrightText: 2026 Curtis Conard
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace GlpiPlugin\Apicompat;

use Entity;
use Glpi\Api\HL\Controller\AbstractController;
use Glpi\Api\HL\Doc as Doc;
use Glpi\Api\HL\ResourceAccessor;
use Glpi\Api\HL\Route;
use Glpi\Api\HL\RouteVersion;
use Glpi\Http\Request;
use Glpi\Http\Response;
use PluginTagTag;
use PluginTagTagItem;
use Session;

#[Route(path: '/Plugin/Tag', priority: 1, tags: ['PluginTag'])]
final class TagController extends AbstractController
{
    protected static function getRawKnownSchemas(string $api_version): array
    {
        return [
            'PluginTag_Tag' => [
                'type' => Doc\Schema::TYPE_OBJECT,
                'x-itemtype' => PluginTagTag::class,
                'x-version-introduced' => '2.4.0',
                'properties' => [
                    'id' => [
                        'type' => Doc\Schema::TYPE_INTEGER,
                        'format' => Doc\Schema::FORMAT_INTEGER_INT64,
                        'x-readonly' => true,
                    ],
                    'name' => ['type' => Doc\Schema::TYPE_STRING],
                    'comment' => ['type' => Doc\Schema::TYPE_STRING],
                    'entity' => self::getDropdownTypeSchema(class: Entity::class, full_schema: 'Entity'),
                    'is_recursive' => ['type' => Doc\Schema::TYPE_BOOLEAN],
                    'color' => ['type' => Doc\Schema::TYPE_STRING, 'format' => Doc\Schema::PATTERN_COLOR_HEX],
                    'is_active' => ['type' => Doc\Schema::TYPE_BOOLEAN],
                ]
            ]
        ];
    }

    public static function redefineAPISchemas(array $data): array
    {
        foreach ($data['schemas'] as &$schema) {
            if (!isset($schema['x-itemtype'])) {
                continue;
            }
            if (PluginTagTag::canItemtype($schema['x-itemtype'])) {
                $schema['properties']['tags'] = [
                    'type' => Doc\Schema::TYPE_ARRAY,
                    'x-rights-conditions' => [
                        'read' => static fn() => Session::haveRight(PluginTagTag::$rightname, READ),
                    ],
                    'x-input-field' => '_plugin_tag_tag_values',
                    'items' => [
                        'type' => Doc\Schema::TYPE_OBJECT,
                        'x-full-schema' => 'PluginTag_Tag',
                        'x-join' => [
                            // This is the join with the desired data
                            'table' => PluginTagTag::getTable(),
                            'fkey' => 'plugin_tag_tags_id',
                            'field' => 'id',
                            'ref-join' => [
                                // This is the linking join between the main item and the data needed
                                'table' => PluginTagTagItem::getTable(),
                                'fkey' => 'id', // ID field on the main join table
                                'field' => 'items_id', // items_id field on the linking join table
                                // Join params becomes tags_ref.items_id=_.id
                                'condition' => [
                                    'itemtype' => $schema['x-itemtype']
                                ],
                            ]
                        ],
                        'properties' => [
                            'id' => [
                                'type' => Doc\Schema::TYPE_INTEGER,
                                'description' => 'ID',
                            ],
                            'name' => [
                                'type' => Doc\Schema::TYPE_STRING,
                                'description' => 'Name',
                            ],
                        ]
                    ]
                ];
            }
        }
        return $data;
    }

    #[Route(path: '/', methods: ['GET'])]
    #[RouteVersion('2.4.0')]
    #[Doc\SearchRoute('PluginTag_Tag')]
    public function getTags(Request $request): Response
    {
        return ResourceAccessor::searchBySchema(
            $this->getKnownSchema('PluginTag_Tag', $this->getAPIVersion($request)),
            $request->getParameters()
        );
    }

    #[Route(path: '/{id}', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[RouteVersion('2.4.0')]
    #[Doc\GetRoute('PluginTag_Tag')]
    public function getTag(Request $request, int $id): Response
    {
        return ResourceAccessor::getOneBySchema(
            $this->getKnownSchema('PluginTag_Tag', $this->getAPIVersion($request)),
            $request->getAttributes(),
            $request->getParameters(),
            $id
        );
    }

    #[Route(path: '/', methods: ['POST'])]
    #[RouteVersion('2.4.0')]
    #[Doc\CreateRoute('PluginTag_Tag')]
    public function createTag(Request $request): Response
    {
        return ResourceAccessor::createBySchema(
            $this->getKnownSchema('PluginTag_Tag', $this->getAPIVersion($request)),
            $request->getParameters(),
            [self::class, 'getTag']
        );
    }

    #[Route(path: '/{id}', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    #[RouteVersion('2.4.0')]
    #[Doc\UpdateRoute('PluginTag_Tag')]
    public function updateTag(Request $request, int $id): Response
    {
        return ResourceAccessor::updateBySchema(
            $this->getKnownSchema('PluginTag_Tag', $this->getAPIVersion($request)),
            $request->getAttributes(),
            $request->getParameters(),
        );
    }

    #[Route(path: '/{id}', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    #[RouteVersion('2.4.0')]
    #[Doc\DeleteRoute('PluginTag_Tag')]
    public function deleteTag(Request $request, int $id): Response
    {
        return ResourceAccessor::deleteBySchema(
            $this->getKnownSchema('PluginTag_Tag', $this->getAPIVersion($request)),
            $request->getAttributes(),
            $request->getParameters(),
            $id
        );
    }
}
