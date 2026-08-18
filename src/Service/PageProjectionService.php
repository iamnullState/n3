<?php
declare(strict_types=1);

namespace N3\Service;

final class PageProjectionService
{
    private readonly FeatureImageService $featureImages;

    public function __construct(
        private readonly PageInformationService $information,
        private readonly ?PluginContributionService $pluginContributions = null,
        ?FeatureImageService $featureImages = null,
    ) {
        $this->featureImages = $featureImages ?? new FeatureImageService();
    }

    public function authenticatedDetail(array $page, int $viewerUserId, array $relatedData): array
    {
        $information = $this->information->forPage($page, $viewerUserId);
        if ($this->pluginContributions !== null) {
            $information['plugin_rows'] = $this->pluginContributions->pageInformationRows($page, $information, $relatedData);
        }
        return [
            'id' => (int)$page['id'],
            'space_id' => (int)$page['space_id'],
            'parent_id' => $page['parent_id'] === null ? null : (int)$page['parent_id'],
            'title' => (string)$page['title'],
            'slug' => $page['slug'] === null ? null : (string)$page['slug'],
            'kind' => (string)$page['kind'],
            'content' => (string)$page['content'],
            'position' => (int)$page['position'],
            'is_favorite' => (int)$page['is_favorite'],
            'is_public' => (int)$page['is_public'],
            'feature_image' => $this->featureImages->normalizePath($page['feature_image'] ?? null),
            'feature_image_opacity' => $this->featureImages->normalizeOpacity($page['feature_image_opacity'] ?? FeatureImageService::DEFAULT_OPACITY),
            'content_revision' => (int)$page['content_revision'],
            'created_at' => $information['created_at'],
            'updated_at' => $information['updated_at'],
            'tags' => array_values(is_array($relatedData['tags'] ?? null) ? $relatedData['tags'] : []),
            'references' => array_values(is_array($relatedData['references'] ?? null) ? $relatedData['references'] : []),
            'related' => array_values(is_array($relatedData['related'] ?? null) ? $relatedData['related'] : []),
            'can_edit' => (bool)($relatedData['can_edit'] ?? false),
            'can_manage' => (bool)($relatedData['can_manage'] ?? false),
            'page_information' => $information,
        ];
    }

    public function publicDetail(array $page): array
    {
        return [
            'page' => [
                'slug' => (string)$page['slug'],
                'title' => (string)$page['title'],
            ],
            'feature_image' => $this->featureImages->fromPage($page),
            'page_information' => $this->information->forPage($page, null),
        ];
    }
}
