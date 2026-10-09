<?php

/**
 * @package     Supertext Translation for PrestaShop
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace Supertext\PrestaShop\Controller\Admin;

use PrestaShopBundle\Controller\Admin\FrameworkBundleAdminController;
use Supertext\PrestaShop\Api\SupertextException;
use Supertext\PrestaShop\Settings;
use Supertext\PrestaShop\Translation\EntityTranslator;
use Supertext\PrestaShop\Translation\EntityTypes;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The "Translate with Supertext" page (opened from the Products, Categories and Pages
 * lists and from the product page) and the endpoint it calls once per item and language.
 */
class TranslateController extends FrameworkBundleAdminController
{
    private const MAX_ITEMS = 100;

    /** GET ?type=product&ids=1,2&source=1, or POST from a list's bulk action (product_bulk[] …). */
    public function translateAction(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $selection = EntityTypes::fromBulkSubmission($request->request->all());

            if ($selection === null) {
                $this->addFlash('error', $this->t('Select at least one item to translate.'));

                return $this->redirect($request->headers->get('referer') ?: $this->generateUrl('admin_products_index'));
            }

            return $this->redirectToRoute('supertext_translate', ['type' => $selection[0], 'ids' => implode(',', $selection[1])]);
        }

        $type = (string) $request->query->get('type', 'product');

        if (!isset(EntityTypes::TYPES[$type])) {
            throw $this->createNotFoundException();
        }

        $definition = EntityTypes::get($type);
        $ids        = EntityTypes::ids($request->query->all()['ids'] ?? $request->query->get('id', ''));
        $ids        = \array_slice(array_values(array_filter($ids, static fn (int $id): bool => EntityTranslator::exists($type, $id))), 0, self::MAX_ITEMS);
        $languages  = \Language::getLanguages(false);
        $sourceId   = (int) $request->query->get('source', \Configuration::get('PS_LANG_DEFAULT'));

        if (!\in_array($sourceId, array_map(static fn (array $l): int => (int) $l['id_lang'], $languages), true)) {
            $sourceId = (int) \Configuration::get('PS_LANG_DEFAULT');
        }

        $source  = new \Language($sourceId);
        $targets = array_values(array_filter($languages, static fn (array $l): bool => (int) $l['id_lang'] !== $sourceId));
        $items   = [];

        foreach ($ids as $id) {
            $states = EntityTranslator::states($type, $id, $sourceId, array_map(static fn (array $l): int => (int) $l['id_lang'], $targets));

            $items[] = [
                'id'      => $id,
                'title'   => EntityTranslator::title($type, $id, $sourceId) ?: '#' . $id,
                'editUrl' => EntityTranslator::editUrl($type, $id),
                'states'  => $states,
            ];
        }

        $targetRows = array_map(static fn (array $l): array => [
            'id'     => (int) $l['id_lang'],
            'name'   => $l['name'],
            'code'   => Settings::targetCode(new \Language((int) $l['id_lang'])),
            'active' => (bool) $l['active'],
        ], $targets);

        return $this->render('@Modules/supertext/views/templates/admin/translate.html.twig', [
            'layoutTitle'   => $this->t('Translate with Supertext'),
            'type'          => $type,
            'typeLabel'     => $this->typeLabel($type),
            'items'         => $items,
            'canEdit'       => self::canEdit($definition['tab']),
            'source'        => ['id' => $sourceId, 'name' => $source->name],
            'languages'     => array_map(static fn (array $l): array => ['id' => (int) $l['id_lang'], 'name' => $l['name']], $languages),
            'targets'       => $targetRows,
            'hasApiKey'     => Settings::apiKey() !== '',
            'settingsUrl'   => \Context::getContext()->link->getAdminLink('AdminModules', true, [], ['configure' => 'supertext']),
            'signupUrl'     => Settings::SIGNUP_URL,
            'apiKeyUrl'     => Settings::API_KEY_URL,
            'runUrl'        => $this->generateUrl('supertext_translate_run'),
            'selfUrl'       => $this->generateUrl('supertext_translate', ['type' => $type, 'ids' => implode(',', $ids)]),
            'listUrl'       => \Context::getContext()->link->getAdminLink($definition['tab']),
            'enableSidebar' => false,
        ]);
    }

    /** POST type, id, source, target, overwrite: translates one item into one language. */
    public function runAction(Request $request): JsonResponse
    {
        $type      = (string) $request->request->get('type');
        $id        = (int) $request->request->get('id');
        $overwrite = $request->request->getBoolean('overwrite');

        if (!isset(EntityTypes::TYPES[$type]) || $id <= 0) {
            return $this->json(['ok' => false, 'message' => $this->t('Invalid request.')], 400);
        }

        if (!self::canEdit(EntityTypes::get($type)['tab'])) {
            return $this->json(['ok' => false, 'message' => $this->t('You are not allowed to edit this item.')], 403);
        }

        $source = new \Language((int) $request->request->get('source'));
        $target = new \Language((int) $request->request->get('target'));

        if (!\Validate::isLoadedObject($source) || !\Validate::isLoadedObject($target) || (int) $source->id === (int) $target->id) {
            return $this->json(['ok' => false, 'message' => $this->t('Choose a target language other than the source language.')], 400);
        }

        if (Settings::apiKey() === '') {
            return $this->json(['ok' => false, 'message' => $this->t('Supertext is not set up yet: an administrator needs to enter the API key in the module settings.')]);
        }

        // One translation can take a while; don't let a short PHP limit cut it off.
        if (\function_exists('set_time_limit')) {
            @set_time_limit(Settings::timeout() + 60);
        }

        try {
            $result = EntityTranslator::create()->translate($type, $id, $source, $target, $overwrite, (int) \Context::getContext()->shop->id);
        } catch (SupertextException|\PrestaShopException $e) {
            \PrestaShopLogger::addLog('Supertext: ' . $e->getMessage(), 2, null, EntityTypes::get($type)['class'], $id, true);

            return $this->json(['ok' => false, 'message' => $e instanceof SupertextException ? $this->errorMessage($e) : $e->getMessage()]);
        }

        $kept = \count($result['kept']);

        if ($result['status'] === 'unchanged') {
            $message = $this->t('Already translated, unchanged');
        } else {
            $message = $this->t('Translated (%count% fields)', ['%count%' => \count($result['translated'])]);

            if ($kept > 0) {
                $message .= ' · ' . $this->t('%count% kept', ['%count%' => $kept]);
            }
        }

        return $this->json(['ok' => true, 'status' => $result['status'], 'message' => $message]);
    }

    private static function canEdit(string $tab): bool
    {
        $employee = \Context::getContext()->employee;

        return $employee !== null && \Access::isGranted('ROLE_MOD_TAB_' . strtoupper($tab) . '_UPDATE', (int) $employee->id_profile);
    }

    private function typeLabel(string $type): string
    {
        return match ($type) {
            'category' => $this->t('Categories'),
            'cms'      => $this->t('Pages'),
            default    => $this->t('Products'),
        };
    }

    /** The exception's message in the employee's language; Supertext's own detail stays as sent. */
    private function errorMessage(SupertextException $e): string
    {
        $message = $this->t($e->template(), $e->parameters());

        return $e->detail() !== '' ? $message . ' (' . $e->detail() . ')' : $message;
    }

    /** @param array<string, mixed> $parameters */
    private function t(string $text, array $parameters = []): string
    {
        return \Context::getContext()->getTranslator()->trans($text, $parameters, 'Modules.Supertext.Admin');
    }
}
