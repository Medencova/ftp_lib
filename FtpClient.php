<?php
/**
 * @file
 * Класс для работы с FTP
 */

require_once 'FtpClientException.php';

/**
 * Класс-оболочка для работы с FTP
 */
class FtpClient
{
    /**
     * @var string Имя хоста
     */
    protected $host;

    /**
     * @var int Номер порта
     */
    protected $port;

    /**
     * @var int Режим передачи файлов
     */
    protected $mode;

    /**
     * @var string Имя пользователя
     */
	protected $user;

    /**
     * @var string Пароль
     */
    protected $pass;

    /**
     * @var resource Идентификатор соединения
     */
    protected $conn_id;

    /**
     * Открытие соединения с сервером
     *
     * @param string $host    Имя хоста
     * @param int    $port    Номер порта
     * @param int    $mode    Режим передачи файлов
     * @param int    $timeout Таймаут
     * @throws FtpException
     */
    public function __construct($host, $port = 21, $mode = FTP_BINARY, $timeout = 90)
    {
        $this->host = $host;
        $this->port = $port;
        $this->mode = $mode;

        $this->conn_id =@ ftp_connect($this->host, $this->port, $timeout);

        if (!$this->conn_id) {
            throw new FtpException('Произошла ошибка при подключении к серверу: ' . $this->host);
        }
    }

    /**
     * Авторизация на сервере
     *
     * @param string $user Имя пользователя
     * @param string $pass Пароль
     * @throws FtpException
     */
    public function login($user, $pass)
    {
        $this->user = $user;
        $this->pass = $pass;

        if (@!ftp_login($this->conn_id, $this->user, $this->pass)) {
            throw new FtpException('Не удалось авторизоваться на сервере: ' . $this->host);
        }

        if (@!ftp_raw($this->conn_id, 'OPTS UTF8 ON')) {
            throw new FtpException('Не удалось переключить кодировку на UTF-8');
        }
    }

    /**
     * Переключение пассивного режима
     *
     * @param bool $pasv Переключатель режима
     * @throws FtpException
     */
    public function setPassive($pasv = true)
    {
        if (@!ftp_pasv($this->conn_id, $pasv)) {
            throw new FtpException('Не удалось переключить пассивный режим');
        }
    }

    /**
     * Возврат имени текущей директории
     *
     * @return string Имя текущей директории
     * @throws FtpException
     */
    public function currentDir()
    {
        if (!($curr_dir =@ ftp_pwd($this->conn_id))) {
            throw new FtpException('Не удалось получить имя текущей директории');
        }

        return $curr_dir;
    }

    /**
     * Переход в родительскую директорию
     *
     * @throws FtpException
     */
    public function toParentDir()
    {
        if (@!ftp_cdup($this->conn_id)) {
            $curr_dir = $this->currentDir();
            throw new FtpException('Не удалось перейти в родительскую директорию' .
                ', текущая директория: ' . $curr_dir);
        }
    }

    /**
     * Получение содержимого указанной директории
     *
     * @param string $directory Имя директории на сервере
     * @return array Содержимое директории
     * @throws FtpException
     */
    public function dirContent($directory)
    {
        $raw_list =@ ftp_rawlist($this->conn_id, $directory);

        if ($raw_list === false) {
            $curr_dir = $this->currentDir();
            throw new FtpException('Не удалось получить содержимое директории: ' . $directory .
                ', текущая директория: ' . $curr_dir);
        }
        else if (empty($raw_list)) {
            return $raw_list;
        }
        else {
            return $this->parseList($raw_list);
        }
    }

    /**
     * Парсер вывода содержимого директории
     *
     * @param array $raw_list Содержимое директории
     * @return array Преобразованное содержимое директории
     */
    protected function parseList($raw_list)
    {
        $parsed_list = array();

        foreach ($raw_list as $value) {
            $template = '/^' .
                '(?<perm>[-a-z]+)\s+' .
                '(?<num>[0-9]+)\s+' .
                '(?<owner>[^\s]+)\s+' .
                '(?<group>[^\s]+)\s+' .
                '(?<size>[0-9]+)\s+' .
                '(?<date>(?<mon>[a-z]+)\s+(?<day>[0-9]+)\s+((?<year>[0-9]+)|(?<time>[0-9:]+)))\s+' .
                '(?<name>.*?)$/i';

            $month_number = array(
                'Jan' => '01',
                'Feb' => '02',
                'Mar' => '03',
                'Apr' => '04',
                'May' => '05',
                'Jun' => '06',
                'Jul' => '07',
                'Aug' => '08',
                'Sep' => '09',
                'Oct' => '10',
                'Nov' => '11',
                'Dec' => '12',
            );

            preg_match($template, $value, $match);

            // преобразование даты
            $month = $month_number[$match['mon']];
            $day = mb_strlen($match['day']) < 2 ? 0 . $match['day'] : $match['day'];

            if (empty($match['year'])) {
                $time = $match['time'];

                $date1 = date('Y') . '-' . $month . '-' . $day . ' ' . $time . ':00';
                $date2 = date('Y-m-d h:m:s');

                if (strtotime($date1) > strtotime($date2)) {
                    $year = (int)date('Y') - 1;
                }
                else {
                    $year = date('Y');
                }
                
                $last_modf = $day . '.' . $month . '.' . $year . ' ' . $match['time'];
            }
            else {
                $last_modf = $day . '.' . $month . '.' . $match['year'] . ' 00:00';
            }

            // определение типа элемента (директория, файл, ссылка)
            $name = $match['name'];
            switch (mb_substr($match['perm'], 0, 1)) {
                case 'd':
                    $type = 'dir';
                    break;
                case '-':
                    $type = 'file';
                    break;
                case 'l':
                    $type = 'link';
                    $name = basename($match['name']);
                    break;
                default:
                    $type = '-';
                    break;
            }

            $parsed_list[] = array('type' => $type, 'size' => $match['size'], 'date' => $last_modf, 'name' => $name);
        }

        return $parsed_list;
    }

    /**
     * Смена директории
     *
     * @param string $directory Имя директории
     * @throws FtpException
     */
    public function changeDir($directory)
    {
        if (@!ftp_chdir($this->conn_id, $directory)) {
            $curr_dir = $this->currentDir();
            throw new FtpException('Не удалось перейти в директорию: ' . $directory .
                ', текущая директория: ' . $curr_dir);
        }
    }

    /**
     * Количество элементов в директории
     * 
     * @param string $directory Имя директории
     * @param string $mode Режим подсчета элементов (dirs, files, all)
     * @return int Количество элементов в директории
     * @throws FtpException
     */
    public function dirCount($directory, $mode = 'files')
    {
        $count = 0;
        $type = '';

        try {
            $parsed_list = $this->dirContent($directory);
            if ($mode == 'dirs') {
                $type = 'директорий';
                foreach ($parsed_list as $value) {
                    if ($value['type'] == 'dir') {
                        $count++;
                    }
                }
            }
            else if ($mode == 'files') {
                $type = 'файлов';
                foreach ($parsed_list as $value) {
                    if ($value['type'] == 'file') {
                        $count++;
                    }
                }
            }
            else if ($mode == 'all') {
                $type = 'элементов';
                $count = count($parsed_list);
            }
            
        }
        catch (FtpException $e) {
            $curr_dir = $this->currentDir();
            
            throw new FtpException('Не удалось подсчитать количество ' . $type . ' в директории: ' . $directory .
                ', текущая директория: ' . $curr_dir);
        }

        return $count;
    }

    /**
     * Получение размера указанного файла
     *
     * @param string $remote_file Имя файла на сервере
     * @return int Размер файла в байтах
     * @throws FtpException
     */
    public function fileSize($remote_file)
    {
        $file_size =@ ftp_size($this->conn_id, $remote_file);
        if ($file_size == -1) {
            $curr_dir = $this->currentDir();
            throw new FtpException('Произошла ошибка при определении размера файла: ' . $remote_file .
                ', текущая директория: ' . $curr_dir);
        }

        return $file_size;
    }

    /**
     * Подсчет общего объема файлов в директории
     *
     * @param string $directory Имя директории
     * @return int Общий объем файлов
     * @throws FtpException
     */
    public function dirSize($directory)
    {
        $total_size = 0;

        try {
            $parsed_list = $this->dirContent($directory);
            foreach ($parsed_list as $value) {
                if ($value['type'] == 'file') {
                    $total_size += $value['size'];
                }
            }
        }
        catch (FtpException $e) {
            $curr_dir = $this->currentDir();
            throw new FtpException('Не удалось подсчитать общий размер файлов в директории: ' . $directory .
                ', текущая директория: ' . $curr_dir);
        }
        
        return $total_size;
    }

    /**
     * Скачивание указанного файла с сервера
     *
     * @param string $local_file  Имя локального файла
     * @param string $remote_file Имя файла на сервере
     * @param bool   $rewrite     Перезапись файла
     * @throws FtpException
     */
    public function getFile($local_file, $remote_file, $rewrite = false)
    {
        if ($rewrite == false) {
            if (file_exists($local_file)) {
                return;
            }
        }
        if (@!ftp_get($this->conn_id, $local_file, $remote_file, $this->mode)) {
            $curr_dir = $this->currentDir();
            throw new FtpException('Произошла ошибка при скачивании файла: ' . $remote_file .
                ', текущая директория: ' . $curr_dir);
        }
    }

    /**
     * Закрытие соединения с сервером
     *
     * @throws FtpException
     */
    public function close()
    {
        if (@!ftp_close($this->conn_id)) {
            throw new FtpException('Произошла ошибка при закрытии соединения с сервером: ' . $this->host);
        }
    }
}