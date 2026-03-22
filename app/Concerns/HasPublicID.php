<?php

namespace App\Concerns;

use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\App;


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
