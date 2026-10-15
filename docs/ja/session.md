# DynamoDB セッションハンドラー

`AsyncAws.DynamoDbSession` は、セッションを DynamoDB のテーブルに保存します。
`config/core.php` (レガシーレイアウトでは `app/Config/core.php`) で設定します:

```php
Configure::write('Session', [
    'defaults' => 'php',
    'cookie' => 'MY_APP',
    'handler' => [
        'engine' => 'AsyncAws.DynamoDbSession',
        'table_name' => 'DYNAMODB_TABLE_NAME',
        // Optional (default: 'locking' => true)
        // 'locking' => true,
    ],
    // Minutes; also the lifetime written to the "expires" attribute of each item
    'timeout' => 1440,
    'ini' => [
        'session.cookie_lifetime' => 0,
        // Expired sessions are removed by DynamoDB's Time to Live, not by PHP's garbage collection
        'session.gc_probability' => 0,
    ],
]);
```

- `handler.table_name` は必須です。指定がない場合は `CakeException` が投げられます。
- テーブルが存在し、`id` という名前の文字列のパーティションキーを持ち、`expires` 属性で Time to Live が
  有効になっている必要があります ([セッションテーブルの作成](#creating-the-session-table) を参照)。
- DynamoDB クライアントは `AsyncAws` 設定から作成されるため、セッションが開始される前に
  プラグインを読み込み、`AsyncAws` を設定しておく必要があります。
- 複数のアプリケーションがドメインとテーブルを共有する場合は、互いのセッションを読まないように、
  アプリケーションごとに別のセッションクッキー名 (`Session.cookie`) を付けてください。

`handler` では次のキーを使えます。名前とデフォルト値は、pieceofcake2/aws が使っている
AWS SDK for PHP のセッションハンドラーのオプションと同じです:

| キー | デフォルト | 説明 |
| --- | --- | --- |
| `locking` | `true` | リクエストがセッションを使っている間はセッションをロックし、同時のリクエストが互いの変更を上書きしないようにします。 |
| `consistent_read` | `true` | `locking` がオフのとき、強い整合性のある読み込みを使います。 |
| `session_lifetime` | `Session.timeout` 分 | セッションが期限切れになるまでの秒数。デフォルトは `CakeSession` がチェックするタイムアウトなので、セッションのアイテムはセッションと同時に期限切れになります。`Session.timeout` がない場合は `session.gc_maxlifetime` です。 |
| `hash_key` | `id` | パーティションキーの名前。 |
| `data_attribute` | `data` | セッションデータを保存する属性の名前。 |
| `session_lifetime_attribute` | `expires` | 有効期限を保存する属性の名前。 |
| `max_lock_wait_time` | `10` | 別のリクエストが持っているロックを待つ秒数。 |
| `min_lock_retry_microtime`、`max_lock_retry_microtime` | `10000`、`50000` | ロック取得を再試行する間隔のマイクロ秒数。 |

アイテムは AWS SDK のセッションハンドラーのものと同じです。キーは `<session name>_<session id>`、
データは文字列、有効期限は Unix タイムスタンプで、`lock` 属性がロックされたセッションを示します。
pieceofcake2/aws とこのプラグインは同じテーブルを使えるため、プラグインを切り替えてもユーザーはログインしたままです。

このハンドラーは `async-aws/dynamo-db-session` を使いません。そのハンドラーはセッションをロックせず、
`session_regenerate_id()` (`CakeSession::renew()` と `AuthComponent::login()` が呼び出します) の後に
データが変わらない限り新しい ID でデータを書き込まず、さらに DynamoDB Local が適用しない
DynamoDB API のデフォルトに依存しているためです。

<a id="creating-the-session-table"></a>

## セッションテーブルの作成

AWS CLI でテーブルを作成し、`expires` 属性で Time to Live を有効にします:

```bash
aws dynamodb create-table --table-name DYNAMODB_TABLE_NAME \
    --attribute-definitions AttributeName=id,AttributeType=S \
    --key-schema AttributeName=id,KeyType=HASH \
    --billing-mode PAY_PER_REQUEST
aws dynamodb update-time-to-live --table-name DYNAMODB_TABLE_NAME \
    --time-to-live-specification "Enabled=true,AttributeName=expires"
```

<a id="how-sessions-expire"></a>

## セッションの期限切れの仕組み

期限切れのセッションは DynamoDB 自身が削除します:

- セッションを書き込むたび、また閉じるたびに、その有効期限 (現在時刻に `session_lifetime` を足したもの) を
  `expires` 属性に保存します。
- DynamoDB の [Time to Live](https://docs.aws.amazon.com/amazondynamodb/latest/developerguide/TTL.html) は、
  `expires` の時刻を過ぎたアイテムを削除します。これはバックグラウンドで無料で実行されますが、即座には行われません。
  期限切れのアイテムが最大で約 48 時間、場合によってはそれ以上テーブルに残ることがあります。
- それまでの間、ハンドラーは期限切れのセッションを削除せずに空のセッションとして読み込み、
  そのセッションを次に書き込むときに古いデータが置き換えられます。期限切れのセッションが復活することはありません。

PHP のセッションのガベージコレクションはオフです。例では `session.gc_probability` を `0` にしており、
`Session.ini` に `session.gc_probability` がない場合は `DynamoDbSession` も `0` に設定します。

<a id="garbage-collection"></a>

## ガベージコレクション

Time to Live のないテーブルのために、`DynamoDbSession::gc()` で期限切れのセッションを削除することもできます。
テーブル全体をスキャンして `expires` の時刻を過ぎたアイテムを探し、`BatchWriteItem` で削除します
(1 リクエストあたり 25 アイテムで、未処理のアイテムは何度か再試行します)。
エラーは `session` スコープでログに記録され、リクエストを中断することはありません。

スキャンはテーブルのすべてのアイテムを読み込み、その分の料金がかかります。また、PHP がガベージコレクションに
選んだリクエストの中で実行されるため、Time to Live が有効な場合はオフのままにしてください。
オンにするには、`Session.ini` で確率を設定します。たとえば、セッション開始 1000 回に 1 回にするには次のようにします:

```php
Configure::write('Session', [
    // ...
    'ini' => [
        'session.cookie_lifetime' => 0,
        'session.gc_probability' => 1,
        'session.gc_divisor' => 1000,
    ],
]);
```

<a id="dynamodb-local"></a>

## DynamoDB Local

[DynamoDB Local](https://docs.aws.amazon.com/amazondynamodb/latest/developerguide/DynamoDBLocal.html)
は Time to Live の設定を受け付けますが、期限切れのアイテムを削除するかどうかはバージョンによって異なります
(現在の `amazon/dynamodb-local` イメージは削除します)。期限切れのセッションは常に空として読み込まれます。
テーブルに残っている場合は、手動で削除するか、ローカル開発では上記のようにガベージコレクションをオンにしてください。

`DynamoDbSession` はすべての `UpdateItem` アクションを明示的に送信するため、DynamoDB Local でもセッションが保存されます。
