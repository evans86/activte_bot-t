{{--@extends('layouts.main')--}}
@extends('layouts.app', ['page' => __('Боты'), 'pageSlug' => 'bots'])

@section('content')
    <div class="row">
        <div class="col-md-12">
            <div class="card ">
                <div class="card-header">
                    <h4 class="card-title"> Боты ({{ $allCount }})</h4>
                    <h4 class="card-title"> Новых после 2025-02-10 20:21:56 ({{ $newBots }})</h4>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table tablesorter " id="">
                            <thead class=" text-primary">
                            <tr>
                                <th class="text-center">ID</th>
                                <th class="text-center">Ключи</th>
                                <th class="text-center">Bot-t ID</th>
                                <th class="text-center">Версия</th>
                                <th class="text-center">Links</th>
                                <th class="text-center">ID категории</th>
                                <th class="text-center">Процент</th>
                                <th class="text-center">Баланс</th>
                                <th class="text-center">Создан</th>
                            </tr>
                            </thead>
                            <tbody>
                            @foreach($bots as $bot)
                                <tr>
                                    <td class="text-center">{{ $bot->id }}</td>
                                    <td class="text-center">Private: {{ $bot->public_key }}<br>Public: {{ $bot->private_key }}</td>
                                    <td class="text-center">{{ $bot->bot_id }}</td>
                                    <td class="text-center">{{ $bot->version }}</td>
                                    <td class="text-center">API key: {{ $bot->api_key }}<br>Retail: {{ $bot->retail }}</td>
                                    <td class="text-center">{{ $bot->category_id }}</td>
                                    <td class="text-center">{{ $bot->percent }} %</td>
                                    <td class="text-center">{{ \App\Helpers\BotHelpers::balance($bot) }} руб.</td>
                                    <td class="text-center">{{ $bot->created_at }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="d-flex">
                        {!! $bots->links() !!}
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
