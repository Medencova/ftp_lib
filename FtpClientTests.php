<?php
/**
 * @file
 * Тесты для класса FtpClient
 */

require_once 'FtpClient.php';

try {
    $ftp = new FtpClient('ftp.zakupki.gov.ru', 21, FTP_ASCII, 120);
    $ftp->login('free', 'free');
    $ftp->setPassive(true);
    echo 'Текущая директория: ' . $ftp->currentDir() . "\n";
    $ftp->changeDir('fcs_regions');
    echo 'Директория после смены: ' . $ftp->currentDir() . "\n";
    $ftp->toParentDir();
    echo 'Содержимое директории ' . $ftp->currentDir() .':' . "\n";
    $content = $ftp->dirContent($ftp->currentDir());
    foreach ($content as $element) {
        echo $element['type'] . "\t" . $element['size'] . "\t" . $element['date'] . "\t" . $element['name'] . "\n";
    }
    echo 'Количество элементов в директории ' . $ftp->currentDir() . ': ' . $ftp->dirCount($ftp->currentDir(), 'all') . "\n";
    echo 'Общий объем файлов в директории ' . $ftp->currentDir() . ': ' . $ftp->dirSize($ftp->currentDir()) . "\n";
    echo 'Размер файла ftp01-ECN.strace: ' . $ftp->fileSize($ftp->currentDir() . 'ftp01-ECN.strace') . "\n";
    $temp_file = tempnam(sys_get_temp_dir(), '_ftpclient');
    $ftp->getFile($temp_file, $ftp->currentDir() . 'ftp01-ECN.strace', true);
    echo 'Содержимое файла: ' . $temp_file . "\n";
    echo '========' . "\n" . file_get_contents($temp_file, false, null, 0, 1024) . "\n" . '========' . "\n";
    unlink($temp_file);
    $ftp->close();
}
catch (FTPException $e) {
    echo 'Сообщение об ошибке: ' . $e->getMessage() . "\n";
}
