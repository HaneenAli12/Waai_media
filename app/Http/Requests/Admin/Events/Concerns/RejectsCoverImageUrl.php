<?php

namespace App\Http\Requests\Admin\Events\Concerns;

use Illuminate\Validation\Validator;

trait RejectsCoverImageUrl
{
    /**
     * `cover_image_url` is a read-only, server-generated field. Event images are only accepted as an
     * uploaded `cover_image` file; clients can never supply a URL or an R2 object key. Rejecting it
     * explicitly (instead of silently ignoring it) surfaces clients that echo back API responses.
     */
    protected function rejectCoverImageUrl(Validator $validator): void
    {
        if ($this->has('cover_image_url')) {
            $validator->errors()->add(
                'cover_image_url',
                'The cover_image_url field is read-only. Upload the image as a file using the cover_image field.'
            );
        }
    }
}
