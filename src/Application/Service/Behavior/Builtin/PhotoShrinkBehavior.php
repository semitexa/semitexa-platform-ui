<?php

declare(strict_types=1);

namespace Semitexa\PlatformUi\Application\Service\Behavior\Builtin;

use Semitexa\PlatformUi\Attribute\AsUiBehavior;

/**
 * Photos on a plain file input are shrunk in the browser before the form
 * sends them: no larger than 2400 px, JPEG, EXIF (GPS included) dropped. GIF,
 * SVG, transparent images and inputs that do not take JPEG are left as chosen;
 * a file that cannot be decoded is sent as it was. While it works, the form's
 * submit buttons are disabled and a submit waits for it.
 *
 * The file field (`platform.field`, `control: 'file'`) does this itself; this
 * is for a form that posts files on its own:
 *
 *   <input type="file" name="photos[]" accept="image/*" multiple ui-behavior="photo-shrink">
 *
 * A page with its own Content-Security-Policy must allow `img-src blob:`.
 *
 * The alias is `photo-shrink`, not `image-shrink`: apartmens declares its own
 * `image-shrink`, and a duplicate alias stops the registry at boot.
 */
#[AsUiBehavior(
    name: 'platform.photo-shrink',
    ui: 'photo-shrink',
    script: 'platform-ui:js:image-shrink',
)]
final class PhotoShrinkBehavior {}
