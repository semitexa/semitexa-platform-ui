<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Primitive\Builtin;

use Semitexa\PlatformUi\Attribute\AsUiContract;
use Semitexa\PlatformUi\Attribute\AsUiPrimitive;
use Semitexa\PlatformUi\Domain\Model\Contract\UiExample;
use Semitexa\PlatformUi\Domain\Model\Contract\UiProp;
use Semitexa\PlatformUi\Domain\Model\Contract\UiPropType;

/**
 * Term / details pairs on <dl>; side by side where there is room (a container query), stacked where there is not.
 */
#[AsUiPrimitive(
    name: 'platform.description-list',
    ui: 'description-list',
    template: '@platform-ui/primitives/runtime/description-list.html.twig',
    style: 'platform-ui:css:full',
)]
#[AsUiContract(
    summary: 'Term / details pairs on <dl>; side by side where there is room (a container query), stacked where there is not.',
    props: [
        new UiProp('items', UiPropType::Array, default: [], description: '[{term, details}]'),
        new UiProp('layout', default: 'inline', values: ['inline', 'stacked']),
    ],
    examples: [
        new UiExample('inline', 'Account', ['items' => [['term' => 'Name', 'details' => 'Ada Lovelace'], ['term' => 'Email', 'details' => 'ada@example.test'], ['term' => 'Plan', 'details' => 'Pro']]]),
        new UiExample('stacked', 'Stacked', ['layout' => 'stacked', 'items' => [['term' => 'Status', 'details' => 'Active']]]),
    ],
    previewSafe: true,
)]
final class DescriptionListPrimitive
{
}
