<?php


namespace Konfigurator\SystemService\Common\Utils;


use Amp\Failure;
use Amp\File\Driver;
use Amp\File\File;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Amp\Process\Process;
use Amp\Promise;
use wapmorgan\UnifiedArchive\UnifiedArchive;
use function Amp\asyncCall;
use function Amp\ByteStream\getStderr;
use function Amp\ByteStream\getStdout;
use function Amp\call;
use function Amp\File\filesystem;

final class Utils
{
    /**
     * @param string $path
     * @param Driver $driver
     * @return Promise<string[]>|Failure<\Throwable>
     */
    public static function recursiveScanDirectory(string $path, Driver $driver = null): Promise
    {
        return call(static function () use ($path, $driver) {

            try {

                if (!$driver) {
                    $driver = filesystem();
                }

                /** @var string[] $filesTree */
                $filesTree = [];

                //$dirsTree = [];

                /** @var string[] $offFiles */
                $offFiles = [];

                /** @var string[] $files */
                $files = yield $driver->scandir($path);
                foreach ($files as $k => $v) {
                    $files[$k] = $path . DIRECTORY_SEPARATOR . $v;
                    $offFiles[$k] = $v;
                }

                //$dirsTree[$path] = [];

                while (($file = array_shift($files)) !== null) {
                    $offPath = array_shift($offFiles);
                    if (true === (yield $driver->isdir($file))) {
                        //$dirsTree[$file] = [];
                        $filesTree[] = [
                            'type' => 'directory',
                            'path' => $file,
                            'offPath' => $offPath,
                        ];
                        /** @var string[] $temp2 */
                        $temp2 = [];
                        /** @var string[] $temp */
                        $temp = yield $driver->scandir($file);
                        foreach ($temp as $k => $v) {
                            $temp[$k] = $file . DIRECTORY_SEPARATOR . $v;
                            $temp2[$k] = $offPath . DIRECTORY_SEPARATOR . $v;
                        }
                        array_unshift($files, ...$temp);
                        array_unshift($offFiles, ...$temp2);
                    } else {
                        $filesTree[] = [
                            'type' => 'file',
                            'path' => $file,
                            'offPath' => $offPath,
                        ];
                        //$dirsTree[dirname($file)][] = $file;
                    }
                }

                return $filesTree;

            } catch (\Throwable $e) {
                return new Failure($e);
            }

        });
    }

    /**
     * @param string $path
     * @param Driver|null $driver
     * @return Promise<void>
     */
    public static function freeDirectory(string $path, Driver $driver = null): Promise
    {
        return call(static function () use ($path, $driver) {
            try {

                if (!$driver) {
                    $driver = filesystem();
                }

                $arr = yield static::recursiveScanDirectory($path, $driver);
                $arr = array_reverse($arr, true);

                foreach ($arr as $item) {

                    if ($item['type'] == 'file') {

                        //dump("remove file " . $item['path']);
                        yield $driver->unlink($item['path']);

                    } else if ($item['type'] == 'directory') {

                        //dump("remove dir " . $item['path']);
                        yield $driver->rmdir($item['path']);

                    }

                }

            } catch (\Throwable $e) {
                return new Failure($e);
            }
        });
    }

    /**
     * @param string $path
     * @param Driver|null $driver
     * @return Promise<void>
     */
    public static function recursiveRemoveDirectory(string $path, Driver $driver = null): Promise
    {
        return call(static function () use ($path, $driver) {
            try {

                if (!$driver) {
                    $driver = filesystem();
                }

                yield static::freeDirectory($path, $driver);

                yield $driver->rmdir($path);

            } catch (\Throwable $e) {
                return new Failure($e);
            }
        });
    }

    /**
     * @param Driver $driver
     * @param string $targetPath
     * @param string $composerName
     * @return Promise<string>
     */
    public static function installLocalComposer(Driver $driver, string $targetPath, string $composerName = 'composer'): Promise
    {
        return call(static function () use ($driver, $targetPath, $composerName) {

            try {

                $client = HttpClientBuilder::buildDefault();

                $composerSetupFilepath = $targetPath . DIRECTORY_SEPARATOR . 'composer-setup.php';

                /** @var File $composerSetupFile */
                $composerSetupFile = yield $driver->open($composerSetupFilepath, 'w');

                /** @var Response $response */
                $response = yield $client->request(new Request("https://getcomposer.org/installer"));
                while (($data = yield $response->getBody()->read()) !== null) {
                    yield $composerSetupFile->write($data);
                }
                yield $composerSetupFile->close();

                if (!hash_file('sha384', $composerSetupFilepath) === 'e5325b19b381bfd88ce90a5ddb7823406b2a38cff6bb704b0acc289a09c8128d4a8ce2bbafcd1fcbdc38666422fe2806') {
                    yield $driver->unlink($composerSetupFilepath);
                    throw new \Error("Failed to download composer!");
                }

                yield Utils::runProcess("php composer-setup.php --install-dir=./ --filename={$composerName}", $targetPath);

                yield $driver->unlink($composerSetupFilepath);

                $composerFilepath = $targetPath . DIRECTORY_SEPARATOR . $composerName;

                if (false === yield $driver->exists($composerFilepath)) {
                    throw new \Error("Invalid composer file!");
                }

                return $composerFilepath;

            } catch (\Throwable $e) {
                return new Failure($e);
            }

        });
    }

    /**
     * @param string $source
     * @param string $destination
     * @param Driver|null $driver
     * @return Promise<void>
     */
    public static function copyFile(string $source, string $destination, Driver $driver = null): Promise
    {
        return call(static function () use ($source, $destination, $driver) {

            try {

                if (!$driver) {
                    $driver = filesystem();
                }

                /** @var File $source */
                $source = yield $driver->open($source, 'r');
                /** @var File $destination */
                $destination = yield $driver->open($destination, 'w');

                while (!$source->eof()) {
                    $chunk = yield $source->read();
                    yield $destination->write($chunk);
                }

            } catch (\Throwable $e) {
                return new Failure($e);
            } finally {
                if (is_object($source)) {
                    yield $source->close();
                }
                if (is_object($destination)) {
                    yield $destination->close();
                }
            }

        });
    }

    /**
     * @param string $process
     * @param string $cwd
     * @return Promise<int>|Failure<\Throwable>
     */
    public static function runProcess(string $process, string $cwd = ''): Promise
    {
        return call(static function () use ($process, $cwd) {

            try {

                $process = new Process($process, $cwd);
                yield $process->start();

                $failure = null;

                asyncCall(static function (Process $process) use (&$failure) {
                    try {
                        $stdout = getStdout();
                        while (($out = yield $process->getStdout()->read()) !== null) {
                            yield $stdout->write($out);
                        }
                    } catch (\Throwable $e) {
                        $failure = $e;
                    }
                }, $process);

                asyncCall(static function (Process $process) use (&$failure) {
                    try {
                        $stderr = getStderr();
                        while (($out = yield $process->getStderr()->read()) !== null) {
                            yield $stderr->write($out);
                        }
                    } catch (\Throwable $e) {
                        $failure = $e;
                    }
                }, $process);

                $code = yield $process->join();

                if (!empty($failure)) {
                    return new Failure($failure);
                }

                return $code;

            } catch (\Throwable $e) {
                return new Failure($e);
            }

        });
    }

    /**
     * @param string $srcFilepath
     * @param string $destinationDir
     *
     * @return void
     *
     * @throws \Throwable
     */
    public static function syncExtractArchive(string $srcFilepath, string $destinationDir): void
    {
        UnifiedArchive::open($srcFilepath)->extractFiles($destinationDir);
    }

    /**
     * @param string $instanceDir
     * @return Promise<string>|Failure<\Throwable>
     */
    public static function getGitCommitHash(string $instanceDir): Promise
    {
        return call(static function () use ($instanceDir) {

            try {

                $process = new Process('git log -1 --format="%H"', $instanceDir);
                yield $process->start();

                $output = trim(yield $process->getStdout()->read());

                yield $process->join();

                return $output;

            } catch (\Throwable $e) {
                return new Failure($e);
            }

        });
    }

    public static function isImplementsClassname($target, string $compareClassname): bool
    {
        return array_search($compareClassname, class_implements($target)) !== false;
    }

    public static function compareClassname($target, string $compareClassname): bool
    {
        return $target == $compareClassname
            || is_a($target, $compareClassname)
            || is_subclass_of($target, $compareClassname)
            //|| $target instanceof $compareClassname
        ;
    }

    /**
     * Returns true if $arr is assoc array
     *
     * @param array $arr
     * @return bool
     */
    public static function isAssoc(array $arr)
    {
        if (array() === $arr) return false;
        return array_keys($arr) !== range(0, count($arr) - 1);
    }
}