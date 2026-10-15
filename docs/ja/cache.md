# DynamoDB キャッシュエンジン

`AsyncAws.DynamoDb` は、キャッシュエントリーを DynamoDB のテーブルに保存します。
`config/bootstrap.php` (レガシーレイアウトでは `app/Config/bootstrap.php`) または `config/core.php` で設定します:

```php
Cache::config('default', [
    'engine' => 'AsyncAws.DynamoDb',
    'table' => 'DYNAMODB_TABLE_NAME',
    'prefix' => 'myapp_',
    'duration' => '+1 hours',
]);
```

このエンジンには、すべてのキャッシュエンジンに共通の設定 (`prefix`、`duration`、`groups`、...) に加えて、
次の設定があります:

| 設定 | デフォルト | 説明 |
| --- | --- | --- |
| `table` | (必須) | テーブルの名前。指定がない場合、`Cache::config()` は `CacheException` を投げます。 |
| `keyAttribute` | `id` | 文字列のパーティションキーの名前。 |
| `valueAttribute` | `value` | 値を保存する属性の名前。 |
| `expiresAttribute` | `expires` | Time to Live が使う、有効期限 (Unix タイムスタンプ) を保存する属性の名前。 |
| `consistentRead` | `false` | 強い整合性のある読み込みを使います。 |
| `clientConfig` | `[]` | このキャッシュ用に `AsyncAws` 設定にマージするオプション。例: `['region' => 'us-east-1']`。 |
| `client` | `null` | クライアントを作成する代わりに使う `AsyncAws\DynamoDb\DynamoDbClient`。 |

- 整数は数値として保存されるため、`Cache::increment()` と `Cache::decrement()` はアトミックです。
  `MemcachedEngine` と同様に、キーが存在しないか期限切れの場合は失敗します (`false` を返します)。
  その他の値はシリアライズされ、バイナリとして保存されます。
- `duration` が `0` の場合、期限切れにならないアイテムを書き込みます。
- 期限切れのアイテムが返されることはありません。DynamoDB がそれらを削除するように、
  テーブルで `expires` 属性の Time to Live を有効にしてください。`gc()` は何もしません。
- `Cache::clear()` はテーブル全体をスキャンし、キーが設定の `prefix` で始まるアイテムを削除します
  (`Cache::clear(true)` では期限切れのものだけを削除します)。スキャンはテーブルのすべてのアイテムを読み込み、
  その分の料金がかかるため、アプリケーションごとに別の `prefix` または別のテーブルを使い、頻繁には呼び出さないでください。
- グループは `RedisEngine` と同じように動作します。各グループはテーブルに保存されたバージョン番号を持ち、
  `Cache::clearGroup()` がそれを増やします。古いエントリーは期限切れになるまでテーブルに残ります。
- 1 つのアイテムは 400 KB までです。それより大きな値は書き込まれず、`Cache::write()` は `false` を返し、
  エラーがログに記録されます。
- 失敗したリクエストは `cache` スコープでログに記録され、その操作は `false` を返します。

アイテムは pieceofcake2/aws の `Aws.DynamoDb` エンジンのものと同じなので、両方のエンジンで同じテーブルを使えます。

<a id="creating-the-table"></a>

## テーブルの作成

テーブルには `id` (`keyAttribute`) という名前の文字列のパーティションキーが必要です。
`createTable()` はオンデマンドキャパシティでテーブルを作成し、Time to Live を有効にします:

```php
Cache::engine('default')->createTable();
```

または AWS CLI で作成します:

```bash
aws dynamodb create-table --table-name DYNAMODB_TABLE_NAME \
    --attribute-definitions AttributeName=id,AttributeType=S \
    --key-schema AttributeName=id,KeyType=HASH \
    --billing-mode PAY_PER_REQUEST
aws dynamodb update-time-to-live --table-name DYNAMODB_TABLE_NAME \
    --time-to-live-specification "Enabled=true,AttributeName=expires"
```
