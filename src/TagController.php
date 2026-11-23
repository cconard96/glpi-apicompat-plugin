<?php

/**
 * SPDX-FileCopyrightText: 2026 Curtis Conard
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace GlpiPlugin\Apicompat;

use CommonDBTM;
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
    /** @var array<string, int[]|null> */
    private static array $visible_tag_ids_cache = [];

    protected static function getRawKnownSchemas(string $api_version): array
    {
        return [
            'PluginTag_Tag' => [
                'type' => Doc\Schema::TYPE_OBJECT,
                'x-itemtype' => PluginTagTag::class,
                'x-version-introduced' => '2.4.0',
                'x-rights-conditions' => [
                    'read' => static fn() => Session::haveRight(PluginTagTag::$rightname, READ),
                ],
                'properties' => [
                    'id' => [
                        'type' => Doc\Schema::TYPE_INTEGER,
                        'format' => Doc\Schema::FORMAT_INTEGER_INT64,
                        'readOnly' => true,
                    ],
                    'name' => ['type' => Doc\Schema::TYPE_STRING, 'required' => true],
                    'comment' => ['type' => Doc\Schema::TYPE_STRING],
                    'entity' => self::getDropdownTypeSchema(class: Entity::class, full_schema: 'Entity'),
                    'is_recursive' => ['type' => Doc\Schema::TYPE_BOOLEAN],
                    'color' => ['type' => Doc\Schema::TYPE_STRING, 'pattern' => Doc\Schema::PATTERN_COLOR_HEX],
                    'is_active' => ['type' => Doc\Schema::TYPE_BOOLEAN],
                    'type_menu' => [
                        'type' => Doc\Schema::TYPE_ARRAY,
                        'x-field' => 'type_menu',
                        'description' => 'Itemtypes this tag can be applied to. An empty list means the tag can be applied to any itemtype.',
                        'items' => [
                            'type' => Doc\Schema::TYPE_STRING,
                            // Stored as a JSON-encoded string. The plugin re-encodes arrays on write (PluginTagTag::encodeSubtypes()).
                            'x-mapped-from' => 'type_menu',
                            'x-mapper' => static function ($v) {
                                if (is_array($v)) {
                                    return $v;
                                }
                                if (!is_string($v) || $v === '') {
                                    return [];
                                }
                                $decoded = json_decode($v, true);
                                return is_array($decoded) ? array_values($decoded) : [];
                            },
                        ],
                    ],
                ]
            ]
        ];
    }

    /**
     * Filter tag IDs down to the tags that can be applied to the given item.
     * A tag is valid if it is active, is allowed for the item's itemtype and, for entity-assigned items,
     * is in the item's entity or is a recursive tag in one of its ancestor entities.
     * Non-numeric values (such as new tag requests) are discarded.
     * @param CommonDBTM $item The item being added or updated
     * @param array $tag_values The requested tag IDs
     * @return int[]
     */
    public static function filterTagValuesForItem(CommonDBTM $item, array $tag_values): array
    {
        global $DB;

        $tag_ids = array_values(array_unique(array_map(
            intval(...),
            array_filter($tag_values, static fn($v) => is_int($v) || (is_string($v) && ctype_digit($v)))
        )));
        if ($tag_ids === []) {
            return [];
        }

        $where = [
            'id' => $tag_ids,
            'is_active' => 1,
        ];
        if ($item->isEntityAssign()) {
            $entity_id = $item->input['entities_id'] ?? $item->fields['entities_id'] ?? null;
            if ($entity_id === null) {
                return [];
            }
            $where[] = getEntitiesRestrictCriteria(PluginTagTag::getTable(), '', (int) $entity_id, true);
        }

        $iterator = $DB->request([
            'SELECT' => ['id', 'type_menu'],
            'FROM' => PluginTagTag::getTable(),
            'WHERE' => $where,
        ]);
        $valid_ids = [];
        foreach ($iterator as $row) {
            $allowed_types = ($row['type_menu'] ?? '') === '' ? [] : json_decode((string) $row['type_menu'], true);
            if (!is_array($allowed_types) || $allowed_types === [] || in_array($item::class, $allowed_types, true)) {
                $valid_ids[] = (int) $row['id'];
            }
        }
        // Keep the requested order
        return array_values(array_intersect($tag_ids, $valid_ids));
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
    public function updateTag(Request $request): Response
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
    public function deleteTag(Request $request): Response
    {
        return ResourceAccessor::deleteBySchema(
            $this->getKnownSchema('PluginTag_Tag', $this->getAPIVersion($request)),
            $request->getAttributes(),
            $request->getParameters()
        );
    }
}
