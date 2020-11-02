<?php

namespace Bazar\Models;

use Bazar\Casts\Inventory;
use Bazar\Casts\Prices;
use Bazar\Concerns\BazarRoutable;
use Bazar\Concerns\Filterable;
use Bazar\Concerns\HasMedia;
use Bazar\Concerns\Sluggable;
use Bazar\Concerns\Stockable;
use Bazar\Contracts\Breadcrumbable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

class Product extends Model implements Breadcrumbable
{
    use BazarRoutable, Filterable, HasMedia, Sluggable, SoftDeletes, Stockable;

    /**
     * The accessors to append to the model's array form.
     *
     * @var array
     */
    protected $appends = [
        'price',
        'formatted_price',
    ];

    /**
     * The attributes that should have default values.
     *
     * @var array
     */
    protected $attributes = [
        'prices' => '[]',
        'options' => '[]',
        'inventory' => '[]',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'options' => 'array',
        'prices' => Prices::class,
        'inventory' => Inventory::class,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'name',
        'slug',
        'prices',
        'options',
        'inventory',
        'description',
    ];

    /**
     * Get the filter options for the model.
     *
     * @return array
     */
    public static function filters(): array
    {
        return [
            'state' => [
                'all' => __('All'),
                'available' => __('Available'),
                'trashed' => __('Trashed')
            ],
            'category' => array_map('__', Category::pluck('name', 'id')->toArray()),
        ];
    }

    /**
     * Get the orders for the product.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphToMany
     */
    public function orders(): MorphToMany
    {
        return $this->morphedByMany(Order::class, 'itemable', 'items')
                    ->withPivot(['id', 'price', 'tax', 'quantity', 'properties'])
                    ->withTimestamps()
                    ->as('item')
                    ->using(Item::class);
    }

    /**
     * Get the carts for the product.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphToMany
     */
    public function carts(): MorphToMany
    {
        return $this->morphedByMany(Cart::class, 'itemable', 'items')
                    ->withPivot(['id', 'price', 'tax', 'quantity', 'properties'])
                    ->withTimestamps()
                    ->as('item')
                    ->using(Item::class);
    }

    /**
     * Get all of the categories for the product.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class);
    }

    /**
     * Get the variations for the product.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function variations(): HasMany
    {
        return $this->hasMany(Variation::class);
    }

    /**
     * Get the variations attribute.
     *
     * @return \Illuminate\Support\Collection
     */
    public function getVariationsAttribute(): Collection
    {
        return $this->getRelationValue('variations')->each(function (Variation $variation) {
            $variation->setRelation(
                'product', $this->withoutRelations()->makeHidden('variations')
            )->makeHidden('product');
        });
    }

    /**
     * Get the variation of the given option.
     *
     * @param  array  $option
     * @return \Bazar\Models\Variation|null
     */
    public function variation(array $option): ?Variation
    {
        return $this->variations->sortBy(static function (Variation $variation) {
            return array_count_values($variation->option)['*'] ?? 0;
        })->first(function (Variation $variation) use ($option) {
            $option = array_replace(array_fill_keys(array_keys($this->options), '*'), $option);

            foreach ($variation->option as $key => $value) {
                if ($value === '*') {
                    $option[$key] = $value;
                }
            }

            return empty(array_diff(array_intersect_key($variation->option, $option), $option));
        });
    }

    /**
     * Get the breadcrumb label.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return string
     */
    public function getBreadcrumbLabel(Request $request): string
    {
        return $this->name;
    }

    /**
     * Retrieve the child model for a bound value.
     *
     * @param  string  $childType
     * @param  mixed  $value
     * @param  string|null  $field
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    public function resolveChildRouteBinding($childType, $value, $field): ?Model
    {
        if ($childType === 'variation' && preg_match('/bazar/', Route::getCurrentRoute()->getName())) {
            return $this->variations()->where(
                $field ?: $this->variations()->getRelated()->getRouteKeyName(), $value
            )->withTrashed()->firstOrFail();
        }

        return parent::resolveChildRouteBinding($childType, $value, $field);
    }

    /**
     * Scope the query only to the given search term.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  string  $value
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeSearch(Builder $query, string $value): Builder
    {
        return $query->where(static function (Builder $query) use ($value) {
            return $query->where('name', 'like', "{$value}%")
                        ->orWhere('inventory->sku', 'like', "{$value}");
        });
    }

    /**
     * Scope the query only to the given category.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  int  $value
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeCategory(Builder $query, int $value): Builder
    {
        return $query->whereHas('categories', function (Builder $query) use ($value) {
            return $query->where('categories.id', $value);
        });
    }
}
