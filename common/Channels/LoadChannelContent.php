<?php

namespace Common\Channels;

use App\Channel;
use App\Genre;
use BadMethodCallException;
use Common\Core\Prerender\Actions\ReplacePlaceholders;
use Common\Database\Datasource\Datasource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\AbstractPaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class LoadChannelContent
{
    public function execute(
        Channel $channel,
        array $params = [],
        Channel $parent = null,
    ): ?AbstractPaginator {
        $params['perPage'] = $params['perPage'] ?? 50;
        if (!isset($params['orderBy']) && !isset($params['order'])) {
            $params['order'] = Arr::get($channel->config, 'contentOrder');
        }

        $this->maybeConnectToGenre($channel, $params, $parent);

        $contentType = Arr::get($channel->config, 'contentType');

        if ($contentType === 'listAll') {
            return $this->paginateAllContentFromDatabase($channel, $params);
        } else {
            $paramsKey = json_encode($params);
            return Cache::remember(
                // use "updated at" so channel changes from admin area will automatically
                // cause new cache item, without having to clear cache manually
                "channels.$channel->id.$channel->updated_at.$paramsKey",
                1440,
                fn() => $this->loadManuallySpecifiedModels($channel, $params),
            );
        }
    }

    private function paginateAllContentFromDatabase(
        Channel $channel,
        array $params,
    ): AbstractPaginator {
        $contentModel = Arr::get($channel->config, 'contentModel');
        $methodName = sprintf('all%s', ucfirst(Str::plural($contentModel)));

        // if channel specifies a method to load this model, use that
        if (method_exists($channel, $methodName)) {
            return $channel->{$methodName}($params);
            // otherwise do a basic pagination for the model
        } else {
            $namespace = modelTypeToNamespace($contentModel);
            $datasource = new Datasource(app($namespace)::query(), $params);
            return $datasource->paginate();
        }
    }

    private function loadManuallySpecifiedModels(
        Channel $channel,
        array $params,
    ): AbstractPaginator {
        $contentModel = Arr::get($channel, 'config.contentModel');
        $builder = $channel->{Str::plural($contentModel)}();
        $datasource = new Datasource($builder, $params);
        $order = $datasource->getOrder();

        // get only column name, in case it's prefixed with table name
        if (last(explode('.', $order['col'])) === 'popularity') {
            $datasource->order = false;
            try {
                $builder->orderByPopularity($order['dir']);
            } catch (BadMethodCallException $e) {
                //
            }
        }

        $pagination = $datasource->paginate();
        $pagination->transform(function (Model $model) use ($channel, $params) {
            $model['channelable_id'] = $model->pivot->id;
            $model['channelable_order'] = $model->pivot->order;
            if ($model instanceof Channel) {
                $model->loadContent(
                    array_merge($params, [
                        // clear parent channel pagination params and only load 12 items per nested channel
                        'perPage' => 12,
                        'page' => 1,
                        'paginate' => 'simple',
                        // clear this so nested channel always uses sorting order set in that channel's config
                        'order' => null,
                    ]),
                    $channel,
                );
            }
            return $model;
        });
        return $pagination;
    }

    private function maybeConnectToGenre(
        Channel $channel,
        array $params = [],
        Channel $parent = null,
    ): void {
        if (
            !Arr::get($params, 'forAdmin') &&
            Arr::get($channel->config, 'connectToGenreViaUrl')
        ) {
            $filter = Arr::get($params, 'filter');
            if (!$filter) {
                abort(404);
            }
            $genre =
                $parent->genre ??
                Genre::where('name', $filter)
                    ->select(['id', 'name', 'display_name'])
                    ->firstOrFail();
            $channel->setAttribute('genre', $genre);

            if (!$genre->display_name) {
                $genre->display_name = ucwords($genre->name);
            }

            $channel->name =
                app(ReplacePlaceholders::class)->execute($channel->name, [
                    'channel' => $channel,
                ]) ?:
                    $channel->name;
        }
    }
}
