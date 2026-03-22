<?php

namespace App\Concerns;

use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;

class PublicID {
    const ID_LENGTH = 11;
    const USE_VOWELS = false;
    public static ?array $censoredWords = null;

    const CHARS = self::USE_VOWELS
        ? '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz_-'
        : '0123456789BCDFGHJKLMNPQRSTVWXYZbcdfghjklmnpqrstvwxyz';

    const CENSORED_WORDS_FILE_PATH = __DIR__ . (self::USE_VOWELS ? '/data/censored.json' : '/data/censored_no_vowels.json');


    public static function generate(): string {
        $id = '';
        $maxIndex = strlen(self::CHARS) - 1;
        for ($i = 0; $i < self::ID_LENGTH; $i++) {
            $id .= self::CHARS[random_int(0, $maxIndex)];
        }
        $has_censored_word = in_array(strtolower($id), self::censoredWords());
        if ($has_censored_word) return self::generate();
        return $id;
    }

    private static function censoredWords(): array {
        if (!static::$censoredWords) {
            $path = static::CENSORED_WORDS_FILE_PATH;
            if (!file_exists($path)) throw new Exception("Censored word list not found", 404);
            $censored_word_file = file_get_contents($path);
            static::$censoredWords = json_decode($censored_word_file);
        }
        return static::$censoredWords;
    }

    public static function getMultiple(string $table, int $count, string $field = 'public_id'): array {
        $ids = [];
        while (count($ids) < $count) {
            $ids[] = self::generate();
        }
        $conflicts = DB::table($table)->whereIn($field, $ids)->pluck($field)->toArray();
        if (count($conflicts) > 0) {
            $ids = array_diff($ids, $conflicts);
            $ids = array_values($ids);
            $ids = array_merge($ids, self::getMultiple($table, $count - count($ids), $field));
        }
        return $ids;
    }
}

trait HasPublicID {
    abstract public function publicIdField(): string;
    abstract public function healUrls(): bool;

    public static function getRandom(Model $model): string {
        $modelString = get_class($model) ?? null;
        if (!$modelString) throw new Exception("Model class not found", 404);
        while (true) {
            $id = PublicID::generate();
            if (!App::make($modelString)::where($model->publicIdField(), '=', $id)->first()) return $id;
        }
    }

    // https://stackoverflow.com/a/71802958
    public static function bootHasPublicID() {
        static::creating(function ($model) {
            if ($model->public_id && strlen($model->public_id) === PublicID::ID_LENGTH) return;
            $model->public_id = self::getRandom($model);
        });
    }

    public function getRouteKeyName() {
        return $this->publicIdField();
    }
    public function getRouteKey() {
        $id = $this->{$this->getRouteKeyName()};
        if (!$this->name || !$this->healUrls()) return $id;
        $name = preg_replace('/[^a-zA-Z0-9_-]/', '_', $this->name);
        return urlencode($name) . '-' . $id;
    }
    public function resolveRouteBinding($value, $field = null) {
        $id = substr($value, -11);
        $model = parent::resolveRouteBinding($id, $field);
        $route = request()->route()->getName();
        if (!$model || !$model->healUrls() || $value === $model->getRouteKey() || !str_contains($route, 'show')) return $model;
        $url = route($route, $model);
        $query = request()->query();
        if ($query) $url .= '?' . http_build_query($query);
        throw new HttpResponseException(redirect($url));
    }
}
