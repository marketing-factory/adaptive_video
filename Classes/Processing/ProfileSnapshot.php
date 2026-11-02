<?php

declare(strict_types=1);

namespace Mfd\AdaptiveVideo\Processing;

/**
 * Immutable record of everything that influences the encoding result. It is
 * stored with each version; its hash is the profile fingerprint, so any change
 * leads to a new version on the next scan.
 */
final readonly class ProfileSnapshot
{
    /**
     * ffmpeg options that only affect resource usage, not the result. They are
     * left out of the snapshot so tuning them does not re-encode all videos.
     */
    private const RUNTIME_ONLY_OPTIONS = ['-threads'];

    /**
     * @param array<string, mixed> $data
     */
    private function __construct(
        public array $data,
    ) {
    }

    /**
     * @param list<string> $ffmpegOptions
     */
    public static function create(EncodingProfile $profile, array $ffmpegOptions): self
    {
        return new self([
            'profile' => $profile->identifier,
            'encoding' => $profile->toArray(),
            'ffmpegOptions' => self::withoutRuntimeOnlyOptions($ffmpegOptions),
        ]);
    }

    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \InvalidArgumentException('Profile snapshot must be a JSON object.', 1793000010);
        }

        return new self($data);
    }

    public function toJson(): string
    {
        return json_encode(self::sortKeys($this->data), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    public function getFingerprint(): string
    {
        return hash('sha256', $this->toJson());
    }

    /**
     * @param list<string> $options
     * @return list<string>
     */
    private static function withoutRuntimeOnlyOptions(array $options): array
    {
        $result = [];
        $skipValue = false;
        foreach ($options as $option) {
            if ($skipValue) {
                $skipValue = false;
                continue;
            }

            if (in_array($option, self::RUNTIME_ONLY_OPTIONS, true)) {
                $skipValue = true;
                continue;
            }

            $result[] = $option;
        }

        return $result;
    }

    /**
     * Sorts associative keys recursively so the fingerprint does not depend on
     * the order in which a profile was configured. Lists keep their order.
     *
     * @param array<mixed> $data
     * @return array<mixed>
     */
    private static function sortKeys(array $data): array
    {
        if (!array_is_list($data)) {
            ksort($data);
        }

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::sortKeys($value);
            }
        }

        return $data;
    }
}
