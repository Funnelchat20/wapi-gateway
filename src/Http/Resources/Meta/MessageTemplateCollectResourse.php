<?php

namespace Funnelchat\WapiGateway\Http\Resources\Meta;

use Illuminate\Http\Resources\Json\JsonResource;

class MessageTemplateCollectResourse extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'templates' => $this->transformTemplates($this['data']),
            'paging' => $this->transformPaging($this['paging'] ?? []),
        ];
    }

    private function transformTemplates($templates): array
    {
        return collect($templates)->map(function ($template) {
            return [
                'name' => $template['name'],
                'parameter_format' => $template['parameter_format'],
                'components' => $this->transformComponents($template['components']),
                'language' => $template['language'],
                'status' => $template['status'],
                'category' => $template['category'],
                'id' => $template['id'],
            ];
        })->toArray();
    }

    private function transformComponents($components): array
    {
        return collect($components)->map(function ($component) {
            $componentData = [
                'type' => $component['type'],
            ];
            if (isset($component['text'])) $componentData['text'] = $component['text'];
            if (isset($component['format'])) $componentData['format'] = $component['format'];
            if (isset($component['example'])) $componentData['example'] = $component['example'];
            if (isset($component['buttons'])) {
                $componentData['buttons'] = collect($component['buttons'])->map(function ($button) {
                    return [
                        'type' => $button['type'],
                        'text' => $button['text'],
                        'url' => $button['url'] ?? null,
                        'phone_number' => $button['phone_number'] ?? null,
                    ];
                })->toArray();
            }
            return $componentData;
        })->toArray();
    }

    private function transformPaging($paging): array
    {
        return [
            'cursors' => $paging['cursors'] ?? [],
            'next' => $paging['next'] ?? '',
        ];
    }
}
