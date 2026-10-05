<?php

namespace Square1\LaravelIdempotency\Support;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class RequestFingerprint
{
    public function make(Request $request): string
    {
        return hash('sha256', serialize([
            'method' => strtoupper($request->method()),
            'host' => $request->getHost(),
            'path' => $request->getPathInfo(),
            'query' => $this->normalize($request->query->all()),
            'payload' => $this->payload($request),
            'content_type' => $request->getContentTypeFormat(),
        ]));
    }

    private function payload(Request $request): mixed
    {
        if ($request->isJson()) {
            $decoded = json_decode($request->getContent(), true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return ['json' => $this->normalize($decoded)];
            }
        }

        if ($request->request->count() > 0 || $request->files->count() > 0) {
            return [
                'fields' => $this->normalize($request->request->all()),
                'files' => $this->describeFiles($request->files->all()),
            ];
        }

        return ['raw' => $request->getContent()];
    }

    private function normalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->normalize($item);
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $files
     * @return array<array-key, mixed>
     */
    private function describeFiles(array $files): array
    {
        $described = [];

        if (! array_is_list($files)) {
            ksort($files);
        }

        foreach ($files as $key => $file) {
            $described[$key] = match (true) {
                $file instanceof UploadedFile => $this->describeFile($file),
                is_array($file) => $this->describeFiles($file),
                default => null,
            };
        }

        return $described;
    }

    /** @return array<string, int|string|null> */
    private function describeFile(UploadedFile $file): array
    {
        if (! $file->isValid()) {
            return [
                'name' => $file->getClientOriginalName(),
                'path' => $file->getClientOriginalPath(),
                'mime_type' => $file->getClientMimeType(),
                'size' => null,
                'hash' => 'invalid:'.$file->getError(),
            ];
        }

        $hash = hash_file('sha256', $file->getPathname());

        return [
            'name' => $file->getClientOriginalName(),
            'path' => $file->getClientOriginalPath(),
            'mime_type' => $file->getClientMimeType(),
            'size' => $file->getSize(),
            'hash' => $hash === false ? 'unreadable' : $hash,
        ];
    }
}
