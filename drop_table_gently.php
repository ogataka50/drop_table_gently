<?php
/**
 * drop_table_gently
 */
class DropTableGently
{
	const SIZE_PER_TRUNCATE		= 5; //onece truncate size

	public function __construct($argv)
	{
		list($arg_arr, $option_arr) = $this->parse_arg($argv);

		//mysql接続コマンド
		$this->mysql_cmd		= $this->get_mysql_cmd();
		$this->mysqldump_cmd	= $this->get_mysqldump_cmd();

		//get config_ini
		$this->env = $arg_arr[1];
		$this->config_ini = $this->get_config_ini($this->env);

		//onece truncate size
		$this->size_per_truncate = self::SIZE_PER_TRUNCATE;
		if (isset($this->config_ini['size_per_truncate']) &&
			is_numeric($this->config_ini['size_per_truncate']) &&
			$this->config_ini['size_per_truncate'] > 0) {
			$this->size_per_truncate = $this->config_ini['size_per_truncate'];
		}
		//droptable resource_dir
		$this->resource_dir		= dirname(__FILE__) . '/resource';
		if (isset($this->config_ini['resource_dir']) && $this->config_ini['resource_dir']) {
			$this->resource_dir = $this->config_ini['resource_dir'];
		}
		//dump dir
		$this->dump_dir			= '/home/root/maintenance/';
		if (isset($this->config_ini['dump_dir']) && $this->config_ini['dump_dir']) {
			$this->dump_dir = $this->config_ini['dump_dir'];
		}
		//mysql data_file dir
		$this->db_data_dir = '/var/lib/mysql';
		if (isset($this->config_ini['db_data_dir']) && $this->config_ini['db_data_dir']) {
			$this->db_data_dir = $this->config_ini['db_data_dir'];
		}
		//dir for hard_link data_file
		$this->hard_link_dir = $this->db_data_dir;
		if (isset($this->config_ini['hard_link_dir']) && $this->config_ini['hard_link_dir']) {
			$this->hard_link_dir = $this->config_ini['hard_link_dir'];
		}
		$this->exec_user = 'root';
		if (isset($this->config_ini['exec_user']) && $this->config_ini['exec_user']) {
			$this->exec_user = $this->config_ini['exec_user'];
		}

		$this->exec_type					= (isset($arg_arr[2])) ? $arg_arr[2] : '';
		$this->target_resource_file			= (isset($arg_arr[3])) ? $arg_arr[3] . '.csv' : '';
		$this->target_resource_file_path	= $this->resource_dir . '/' . $this->target_resource_file;
		$this->target_table_list			= array(); //dbname => array('host_master'=>'xxxxx','host_slave'=>'xxxxx',table_list => array('xxxx','xxxx'))

		//get option
		$this->flag_dry_run = 0;
		$this->flag_no_dump = 0;
		if (isset($option_arr['shortopts']['d']) || isset($option_arr['longopts']['dry_run'])) {
			$this->flag_dry_run = 1;
		}
		if (isset($option_arr['shortopts']['n']) || isset($option_arr['longopts']['no_dump'])) {
			$this->flag_no_dump = 1;
		}

		$this->data_file_list		= array();

		$this->message_arr = array();

		$this->exec_result_list	= array(
			'success'				=> array(),
			'not_exist_data_file'	=> array(),
			'fail_dump'				=> array(),
			'fail_hard_link'		=> array(),
			'fail_drop'				=> array(),
			'fail_truncate_file'	=> array(),
			'fail_remove_file'		=> array(),
		);

		$this->operation_list = array(
			'check' => array(
				'operation'		=> 'chk_exist_table',
				'description'	=> '対象csvのテーブル存在確認',
			),
			'exec' => array(
				'operation'		=> 'exec_dump_and_drop_gently',
				'description'	=> 'ゆるやかにdump & drop 実行',
			),
		);
	}


	// メイン処理
	public function run()
	{
		$this->write_log('実行開始');

		//引数チェック
		$res_chk = $this->check_exec();
		if (!$res_chk) {
			$this->write_log('設定に不備があったので終了します');
			return;
		}

		//実行
		if (!isset($this->operation_list[$this->exec_type]['operation'])) {
			$this->write_log("FALSE_EXEC_TYPE => " . $this->exec_type);
			return;
		}

		switch ($this->operation_list[$this->exec_type]['operation']) {
			case 'chk_exist_table':
				$res = $this->chk_exist_table();
				break;
			case 'exec_dump_and_drop_gently':
				$res = $this->exec_dump_and_drop_gently();
				break;
			default:
				$this->write_log("FALSE_EXEC_TYPE => " . $this->exec_type);
				return;
				break;
		}

		//ログ出力
		$this->write_log($this->message_arr);
		$this->write_log('実行完了');
		return;
	}

	//config ini取得
	public function get_config_ini($env)
	{
		$config_ini_path	= "./config/{$env}.ini";
		if (!is_file($config_ini_path)) {
			$this->write_log('設定ファイルが存在しません : ' . $config_ini_path);
			exit;
		}
		$config_ini			= parse_ini_file($config_ini_path, true);
		if (!$config_ini) {
			$this->write_log('設定ファイルが不正です : ' . $config_ini_path);
			exit;
		}

		return $config_ini;
	}

	//引数チェック
	public function check_exec()
	{
		$res_chk = true;

		//drop table listファイル存在するか
		if (!is_file($this->target_resource_file_path)) {
			$this->write_log("テーブル定義ファイルが存在しません => " . var_export($this->target_resource_file_path, true));

			$res_chk = false;
			return $res_chk;
		}

		//ファイルから対象テーブル設定情報取得
		$fp = fopen($this->target_resource_file_path, "r");
		while ($line = fgetcsv($fp)) {
			$tmp_db		= $line[0];
			$tmp_table	= $line[1];
			if (isset($this->config_ini['db_list'][$tmp_db]) && $tmp_table) {
				//対象テーブル情報初期化
				if (!isset($this->target_table_list[$tmp_db])) {
					$this->target_table_list[$tmp_db] = array(
						'host_master'	=> $this->config_ini['db_list'][$tmp_db]['host_master'],
						'host_slave'	=> $this->config_ini['db_list'][$tmp_db]['host_slave'],
						'dbname'		=> $this->config_ini['db_list'][$tmp_db]['db_name'],
						'username'		=> $this->config_ini['db_list'][$tmp_db]['username'],
						'password'		=> $this->config_ini['db_list'][$tmp_db]['password'],
						'table_list'	=> array()
					);
				}
				$this->target_table_list[$tmp_db]['table_list'][] = $tmp_table;
			} else {
				$this->write_log("不正なテーブル定義がありました => " . var_export($line, true));
				$res_chk = false;
			}
		}

		//userチェック
		$cmd		= "whoami";
		$is_force	= 1;
		list($output, $ret) = $this->exec_cmd($cmd, $is_force);
		if ($ret != 0) {
			$res_chk = false;
		}
		if (isset($output[0]) && $output[0] != $this->exec_user) {
			$this->write_log('実行ユーザが不正です');
			$this->write_log('実行中ユーザ : ' . $output[0]);
			$this->write_log('指定ユーザ : ' . $this->exec_user);

			$res_chk = false;
		}

		return $res_chk;
	}

	//ゆるやかにdump&drop実行
	public function exec_dump_and_drop_gently()
	{
		//dump
		//ハードリンク貼る
		//table drop
		//実ファイルを徐々にサイズ切り詰めて削除

		//ゆるやかにdump&drop実行できるか確認
		$this->write_log('###########################');
		$this->write_log('START chk_exec_dump_and_drop_gently()');
		$this->write_log('###########################');
		$res_chk = $this->chk_exec_dump_and_drop_gently();
		if (!$res_chk) {
			$str = "実行チェックでエラー。終了します。\n";
			$this->write_log($str);
			exit;
		}
		$this->write_log('###########################');
		$this->write_log('END chk_exec_dump_and_drop_gently()');
		$this->write_log('###########################');
		$this->write_log('');

		if (!$this->flag_no_dump) {
			$this->write_log('###########################');
			$this->write_log('START exec_dump_table()');
			$this->write_log('###########################');
			//対象テーブルをdump
			$this->exec_dump_table();
			$this->write_log('###########################');
			$this->write_log('END exec_dump_table()');
			$this->write_log('###########################');
			$this->write_log('');
		}

		$this->write_log('###########################');
		$this->write_log('START put_hard_link_datafile()');
		$this->write_log('###########################');
		//対象テーブルibdにハードリンク貼る
		$this->put_hard_link_datafile();
		$this->write_log('###########################');
		$this->write_log('END put_hard_link_datafile()');
		$this->write_log('###########################');
		$this->write_log('');

		$this->write_log('###########################');
		$this->write_log('START exec_drop_table()');
		$this->write_log('###########################');
		//対象テーブルdrop実行
		$this->exec_drop_table();
		$this->write_log('###########################');
		$this->write_log('END exec_drop_table()');
		$this->write_log('###########################');
		$this->write_log('');

		$this->write_log('###########################');
		$this->write_log('START remove_datafile_gently()');
		$this->write_log('###########################');
		// ibdファイルをtruncate後に削除
		$this->remove_datafile_gently();
		$this->write_log('###########################');
		$this->write_log('END remove_datafile_gently()');
		$this->write_log('###########################');
		$this->write_log('');


		//投稿メッセージ作成
		$this->make_message();
	}

	private function make_message()
	{
		$this->message_arr[] = '【テーブル削除処理結果】';
		$this->message_arr[] = '【環境】 : ' . $this->env;
		if ($this->flag_dry_run) {
			$this->message_arr[] = '#############';
			$this->message_arr[] = '### dry_run ###';
			$this->message_arr[] = '#############';
		}
		if ($this->flag_no_dump) {
			$this->message_arr[] = '#############';
			$this->message_arr[] = '### no_dump ###';
			$this->message_arr[] = '#############';
		}

		if ($this->exec_result_list['success']) {
			$this->message_arr[] = '';
			$this->message_arr[] = '■テーブル削除成功';
			$this->message_arr[] = "※dumpファイルは{$this->dump_dir}以下";
			foreach ($this->exec_result_list['success'] as $key => $val) {
				$this->message_arr[] = $key;
				foreach ($val as $row) {
					$this->message_arr[] = " └{$row}";
				}
			}
		}
		if ($this->exec_result_list['not_exist_data_file']) {
			$this->message_arr[] = '';
			$this->message_arr[] = '■テーブルのdata_fileがない';
			foreach ($this->exec_result_list['not_exist_data_file'] as $row) {
				$this->message_arr[] = $row;
			}
		}
		if ($this->exec_result_list['fail_dump']) {
			$this->message_arr[] = '';
			$this->message_arr[] = '■dump失敗';
			foreach ($this->exec_result_list['fail_dump'] as $row) {
				$this->message_arr[] = $row;
			}
		}
		if ($this->exec_result_list['fail_hard_link']) {
			$this->message_arr[] = '';
			$this->message_arr[] = '■hard_link失敗';
			foreach ($this->exec_result_list['fail_hard_link'] as $row) {
				$this->message_arr[] = $row;
			}
		}
		if ($this->exec_result_list['fail_drop']) {
			$this->message_arr[] = '';
			$this->message_arr[] = '■drop失敗';
			foreach ($this->exec_result_list['fail_drop'] as $row) {
				$this->message_arr[] = $row;
			}
		}
		if ($this->exec_result_list['fail_truncate_file']) {
			$this->message_arr[] = '';
			$this->message_arr[] = '■data_fileのtruncate処理に失敗';
			foreach ($this->exec_result_list['fail_truncate_file'] as $row) {
				$this->message_arr[] = $row;
			}
		}
		if ($this->exec_result_list['fail_remove_file']) {
			$this->message_arr[] = '';
			$this->message_arr[] = '■data_fileの削除に失敗';
			foreach ($this->exec_result_list['fail_remove_file'] as $row) {
				$this->message_arr[] = $row;
			}
		}
	}

	//対象テーブルをdump
	private function exec_dump_table()
	{
		//mysqldump実行
		foreach ($this->target_table_list as $key_db => $db_info) {
			$dbname			= $db_info['dbname'];
			$dbhost_slave	= $db_info['host_slave'];
			$username		= $db_info['username'];
			$password		= $db_info['password'];

			//dump先dir作成
			if (!is_dir($this->dump_dir)) {
				exec('/bin/mkdir -p ' . $this->dump_dir);
			}

			foreach ($db_info['table_list'] as $key => $table) {
				//dump前にSELECT COUNT(*)
				$sql = "SELECT COUNT(*) FROM {$table}";
				list($output, $ret) = $this->exec_query($sql, $dbhost_slave, $dbname, $username, $password);

				//mysqldump
				$cmd	= "{$this->mysqldump_cmd} -u{$username} -p{$password} -h{$dbhost_slave} {$dbname} {$table} > {$this->dump_dir}dump_{$table}.sql";
				list($output, $ret) = $this->exec_cmd($cmd);
				$ret_str = '成功';
				if ($ret !== 0) {
					$ret_str = '失敗';
					unset($this->target_table_list[$key_db]['table_list'][$key]);
					$this->exec_result_list['fail_dump'][] = "{$key_db}_{$table}";
				}
				$this->write_log('dump実行結果 : ' . $ret_str);
			}
		}

		return;
	}

	//対象テーブルibdにハードリンク貼る
	private function put_hard_link_datafile()
	{
		//sudo ln /usr/local/mysql/data/hoge_db/bk_hoge_tbl.ibd /home/hoge/bk_hoge_tbl.ibd
		foreach ($this->target_table_list as $key_db => $db_info) {
			foreach ($db_info['table_list'] as $key => $table) {
				if (!isset($this->data_file_list[$key_db][$table])) {
					continue;
				}

				foreach ($this->data_file_list[$key_db][$table] as $tmp_data_file) {
					$cmd = "ln {$this->db_data_dir}/{$db_info['dbname']}/{$tmp_data_file} {$this->hard_link_dir}/{$tmp_data_file}";
					if ($db_info['host_master'] != 'localhost') {
						$cmd	= "ssh {$db_info['host_master']} {$cmd}";
					}
					list($output, $ret) = $this->exec_cmd($cmd);
					$ret_str = '成功';
					if ($ret !== 0) {
						$ret_str = '失敗';
						unset($this->target_table_list[$key_db]['table_list'][$key]);
						$this->exec_result_list['fail_hard_link'][] = "{$key_db}_{$table}";
					}
					$this->write_log('hard_link実行結果 : ' . $ret_str);
				}
			}
		}

		return;
	}

	//対象テーブルdrop実行
	private function exec_drop_table()
	{
		//mysqldump実行
		foreach ($this->target_table_list as $key_db => $db_info) {
			$dbname			= $db_info['dbname'];
			$dbhost_master	= $db_info['host_master'];
			$username		= $db_info['username'];
			$password		= $db_info['password'];

			foreach ($db_info['table_list'] as $key => $table) {
				$sql = "DROP TABLE {$table}";
				list($output, $ret) = $this->exec_query($sql, $dbhost_master, $dbname, $username, $password);
				$ret_str = '成功';
				if ($ret !== 0) {
					$ret_str = '失敗';
					unset($this->target_table_list[$key_db]['table_list'][$key]);
					$this->exec_result_list['fail_drop'][] = "{$key_db}_{$table}";
				}
				$this->write_log('drop実行結果 : ' . $ret_str);
			}
		}

		return;
	}

	private function remove_datafile_gently()
	{
		//truncate してからremove
		foreach ($this->target_table_list as $key_db => $db_info) {
			foreach ($db_info['table_list'] as $table) {
				if (!isset($this->data_file_list[$key_db][$table])) {
					continue;
				}

				foreach ($this->data_file_list[$key_db][$table] as $tmp_data_file) {
					$hard_link_path = "{$this->hard_link_dir}/{$tmp_data_file}";
					if ($this->flag_dry_run) {
						$hard_link_path = "{$this->db_data_dir}/{$db_info['dbname']}/{$tmp_data_file}";
					}
					//truncateしつつファイル削除
					$this->truncate_to_remove_file($hard_link_path, $db_info['host_master'], $key_db, $table);
				}
			}
		}
		return;
	}


	private function truncate_to_remove_file($file_path, $host, $db, $table)
	{
		$file_size = 0;

		//ファイルサイズを取得
		$cmd	= "du -m {$file_path} | awk '{print $1}'";
		if ($host != 'localhost') {
			$cmd	= "ssh {$host} {$cmd}";
		}
		$is_force = 1;
		list($output, $ret) = $this->exec_cmd($cmd, $is_force);
		if ($ret == 0) {
			$file_size = $output[0];
			$str = 'file_size : ' . $file_size . ' => ' . $file_path;
			$this->write_log($str);
		}

		//truncateで徐々に切り詰める
		if ($file_size) {
			$i = $this->size_per_truncate;
			while ($i <= $file_size) {
				$tmp_size = $file_size - $i;
				if ($tmp_size < 0) {
					$tmp_size = 0;
				}

				$cmd		= "truncate -s {$tmp_size}M {$file_path}";
				if ($host != 'localhost') {
					$cmd	= "ssh {$host} {$cmd}";
				}

				list($output, $ret) = $this->exec_cmd($cmd);
				if ($ret !== 0) {
					unset($this->target_table_list[$db]['table_list'][$table]);
					$this->exec_result_list['fail_truncate_file'][] = $file_path;
					$this->write_log("truncate : 失敗");
					return;
				}
				//0.1sec sleep
				usleep(100000);
				$i += self::SIZE_PER_TRUNCATE;
			}
			$cmd = "rm {$file_path}";
			if ($host != 'localhost') {
				$cmd	= "ssh {$host} {$cmd}";
			}
			list($output, $ret) = $this->exec_cmd($cmd);
			if ($ret != 0) {
				unset($this->target_table_list[$db]['table_list'][$table]);
				$this->write_log("data_file remove : 失敗 => {$file_path}");
				$this->exec_result_list['fail_remove_file'][] = $file_path;
			} else {
				$this->write_log("data_file remove : 成功 => {$file_path}");
				$tmp_key = "{$db}_{$table}";
				if (!isset($this->exec_result_list['success'][$tmp_key])) {
					$this->exec_result_list['success'][$tmp_key] = array();
				}
				$this->exec_result_list['success'][$tmp_key][] = $file_path;
			}
		}

		return;
	}

	//ゆるやかにdump&drop実行できるか確認
	private function chk_exec_dump_and_drop_gently()
	{
		$res_chk = 1;

		//ユーザがrootか確認
		//対象サーバーにsshできるか確認
		//対象サーバーがマスターか確認
		//ibdファイルの存在確認

		//対象サーバーにsshできるか確認
		//ibdファイルの存在確認
		foreach ($this->target_table_list as $key_db => $db_info) {
			//対象サーバーにsshできるか確認
			if ($db_info['host_master'] != 'localhost') {
				$cmd	= "ssh {$db_info['host_master']} pwd";
				$output	= '';
				$ret	= '';
				$this->write_log($cmd);
				list($output, $ret) = $this->exec_cmd($cmd);
				if ($ret != 0) {
					$str = "{$db_info['dbname_master']} : {$db_info['host_master']} にsshできません";
					$this->write_log($str);
					$res_chk = 0;
				}
			}

			foreach ($db_info['table_list'] as $key => $table) {
				//ibdファイルの存在確認
				$table_data_file_arr = $this->get_table_data_file($this->target_table_list[$key_db]['dbname'], $table, $db_info['host_master']);
				if (!$table_data_file_arr) {
					$str = "{$key_db}.{$table} のdata_fileがありません";
					$this->write_log($str);
					unset($this->target_table_list[$key_db]['table_list'][$key]);
					$this->exec_result_list['not_exist_data_file'][] = "{$key_db}_{$table}";
					//$res_chk = 0;
				} else {
					if (!isset($this->data_file_list[$key_db])) {
						$this->data_file_list[$key_db] = array();
					}
					$this->data_file_list[$key_db][$table] = $table_data_file_arr;
				}
			}
		}

		return $res_chk;
	}

	private function get_table_data_file($db, $table, $host)
	{
		$table_data_file_arr	= array();

		$cmd	= "ls {$this->db_data_dir}/{$db} | egrep '{$table}\.|{$table}#'";
		if ($host != 'localhost') {
			$cmd	= "ssh {$host} {$cmd}";
		}
		$is_force = 1;
		list($output, $ret) = $this->exec_cmd($cmd, $is_force);

		if ($ret == 0 && $output && count($output)) {
			$table_data_file_arr = $output;
		}

		return $table_data_file_arr;
	}


	//SHOW TABLESからテーブル存在確認
	public function chk_exist_table()
	{
		$exist_table_list		= array();
		$not_exitst_table_list	= array();
		foreach ($this->target_table_list as $db_key => $db_info) {
			//対象DBのSHOW TABLES実行
			$table_list = $this->get_table_list($db_info['host_slave'], $db_info['dbname'], $db_info['username'], $db_info['password']);
			foreach ($db_info['table_list'] as $table) {
				if (isset($table_list[$table])) {
					$exist_table_list[] = $db_info['dbname'] . '.' . $table;
				} else {
					$not_exitst_table_list[] = $db_info['dbname'] . '.' . $table;
				}
			}
		}

		//テーブルあり出力
		if ($exist_table_list) {
			$this->write_log('###########################');
			$this->write_log("[TABLE EXISIT]");
			$this->write_log('###########################');
			foreach ($exist_table_list as $row) {
				$this->write_log($row);
			}
		}

		$this->write_log('');

		//テーブルなし出力
		if ($not_exitst_table_list) {
			$this->write_log('###########################');
			$this->write_log("[※TABLE NOT EXISIT※]");
			$this->write_log('###########################');
			foreach ($not_exitst_table_list as $row) {
				$this->write_log($row);
			}
		}

		return;
	}

	//SHOW TABLESしてテーブルリスト取得
	public function get_table_list($db_host, $dbname, $username, $password)
	{
		$table_list	= array();
		$sql		= 'show tables';
		$option		= '-N';
		$is_force 	= 1;
		list($output, $ret) = $this->exec_query($sql, $db_host, $dbname, $username, $password, $option, $is_force);

		if ($ret == 0 && $output) {
			foreach ($output as $key => $val) {
				$table_list[$val] = $val;
			}
		}

		return $table_list;
	}

	//searate arg/opt
	protected function parse_arg($argv)
	{
		$arg_arr	= array();
		$opt_arr	= array(
			'shortopts'	=> array(),
			'longopts'	=> array(),
		);

		foreach ($argv as $key => $val) {
			$pattern = '/^--/';
			$res = preg_replace($pattern, '', $val, -1, $count);
			if ($count) {
				$opt_arr['longopts'][$res] = true;
				continue;
			}
			$pattern = '/^-/';
			$res = preg_replace($pattern, '', $val, -1, $count);
			if ($count) {
				$opt_arr['shortopts'][$res] = true;
				continue;
			}
			$arg_arr[] = $val;
		}

		return array($arg_arr, $opt_arr);
	}

	protected function write_log($text)
	{
		if (is_array($text)) {
			$text = print_r($text, 1);
		}
		$date = date('Y-m-d H:i:s');
		print_r("[{$date}] {$text}\n");
		if (!defined('LOG_FILE')) {
			if (!defined('LOG_FILE_PATH')) {
				define('LOG_FILE_PATH', dirname(__FILE__) . '/logs/');
			}
			if (!file_exists(LOG_FILE_PATH)) {
				mkdir(LOG_FILE_PATH, 0777, true);
			}
			if (!defined('LOG_FILE')) {
				define('LOG_FILE', LOG_FILE_PATH . basename(__FILE__) . '-' . $this->env . '-' . date('Ymd') . '.log');
			}
		}

		return file_put_contents(LOG_FILE, "[{$date}] {$text}\n", FILE_APPEND);
	}

	//exec cmd
	protected function exec_cmd($cmd, $is_force = 0)
	{
		$output	= '';
		$ret	= 0;

		if (!$this->flag_dry_run || $is_force) {
			exec($cmd, $output, $ret);
		}

		$this->write_log($cmd);

		return array($output, $ret);
	}

	//exec query
	protected function exec_query($sql, $db_host, $db_name, $user, $password, $option = '', $is_force = 0)
	{
		$cmd = "{$this->mysql_cmd} -u{$user} -p{$password} -h{$db_host} {$db_name} -e \"{$sql}\"";
		if ($option) {
			$cmd .= " {$option}";
		}
		list($output, $ret) = $this->exec_cmd($cmd, $is_force);

		return array($output, $ret);
	}

	/**
	 * mysqlコマンド文字列
	 * @return string
	 */
	protected function get_mysql_cmd()
	{
		$mysql_client	= 'mysql';
		$mysql_cmd		= sprintf('%s ', $mysql_client);

		return $mysql_cmd;
	}

	/**
	 * mysqldumpコマンド文字列
	 * @return string
	 */
	protected function get_mysqldump_cmd()
	{
		$mysqldump_client	= 'mysqldump';
		$mysqldump_cmd		= sprintf('%s ', $mysqldump_client);

		return $mysqldump_cmd;
	}
}


if (isset($argv) && count($argv) > 1) {
	$obj = new DropTableGently($argv);

	$obj->run();
} else {
	echo 'ex : php exec_dump_and_drop.php [environment] [exec_type] [target_resource_file]' . "\n";
	echo 'config : [localhost|sandbox|staging|production]' . "\n";
	echo 'exec_type : [check|exec]' . "\n";
	echo 'target_resource_file : [file_name : drop_table_list]' . "\n";
}
