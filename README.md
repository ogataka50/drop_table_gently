# drop_table_gently

巨大なテーブルでもゆるやかに削除することによって、負荷をかけずにdrop行うscript

## Usage

```
usage : php drop_table_gently.php [environment] [exec_type] [target_resource_file] [option]

environment : [dev|sandbox|sta|prod] read ./config{$environment}.ini
exec_type : [check|exec]
target_resource_file : [file_name : 20150718/drop_list] read ./resource{$target_resource_file}.csv

option :
        -d --dry_run : mode dry_run
        -n --no_dump : except mysqldump
```

```
$ php drop_table_gently.php stg check 20160302/table_list
 -> 20160302/table_list.csvに列挙されているテーブル存在可否チェック

$ php drop_table_gently.php stg exec 20160302/table_list
 -> 20160302/table_list.csvに列挙されているテーブルをゆるやかにdrop

$ php drop_table_gently.php prod exec 20160302/table_list --dry_run
 -> 20160302/table_list.csvに列挙されているテーブルをゆるやかにdropをdry_runで実行

```

### 引数

- environment
	- 対象の環境を指定。入力値を元にconfginを設定します(./config/{$environment}.ini)
- exec_type
	- check or exec
		- check => target_resource_file内に記載されているテーブルが存在可否を処理します
		- exec => target_resource_file内に記載されているテーブルをゆるやかにdrop table行います
- target_resource_file
	- drop対象のテーブルが記載されたcsvファイルを指定。入力値を元にresource以下からファイルを取得します。(./resource/{$target_resource_file}.csv)
	- ファイルはcsv形式でdropするテーブルを記載(ex:dbname1,tmp_event_ranking)

### オプション

- dry_run
	- -d or --dry_run を指定
		- 処理実行自体はせず、発行するコマンドが出力されます
- no_dump
	- -n or --no_dump を指定
		- デフォルトで実行される実行前に行うdumpファイル取得を省くことができます


## 概要

ゆるやかにdropするおおまかな処理の流れ

1. 実行前チェックとして、DBサーバーがリモート場合はsshできるか、data_fileがあるか確認を行う
1. drop対象のテーブルのdumpファイルを保存
1. dropするテーブルの関連データファイルに対して、ハードリンクを貼ります
ハードリンクを貼ることでdrop時にデータファイルの同期的な削除を防ぎます
1. drop tableを実行
1. ハードリンクを貼ったファイルをtruncateコマンドで徐々にサイズを切り詰めた後に、rmを行います

## 注意点

- 依存コマンド
	- 下記のコマンドがインストール+PATHが通っていること前提としています
		- mysql
		- mysqldump
		- truncate

- データベースサーバーがリモートの場合、ssh後にコマンド実行するので実行ユーザはnon_passでsshできる必要があります
- データベースサーバーがlocalの場合は、configのhostにはlocalhostと設定してください
- innodb_file_per_tableでibdファイルをテーブルごとに分けていることを前提としています

## TODO

 - mysql,mysqldumpでsslとか対応


## Author

[takahiro ogasawara](https://github.com/ogataka50/)