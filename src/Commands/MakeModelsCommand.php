<?php

namespace Radish\LaravelGenerator\Commands;

use Liaosp\Tool\String\Character;
use Radish\LaravelGenerator\Support\Database;
use Radish\LaravelGenerator\Support\VariableConversion;
use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\InputArgument;
use DB;

class MakeModelsCommand extends GeneratorCommand
{
    protected $is_base = false;
    /**
     * The console command name.
     *
     * @var string
     */
//    public function
    protected $name = 'radish:models';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Build the model from the data table.';

    protected $generator;

    public function handle()
    {
        $this->generator = config('generator.model');

        $database = new Database();

        $tables = $database->getSchemaTables();

        if (!count($tables)) {
            $this->info('请创建table后再试');
            return;
        }

        $tableName = $this->getNameInput();

        $tableCollection = Collection::make($tables);

        if ($tableName) {
            $hasTable = $tableCollection->where('name', DB::getTablePrefix() . $tableName)->first();

            if (!$hasTable) {

                $this->info(DB::getTablePrefix() . $tableName . ' 表未创建');
                return;
            }
            $tableCollection = Collection::make([$hasTable]);

        } elseif (count($this->generator['fill'])) {

            $fillTableCollection = Collection::make();
            foreach ($this->generator['fill'] as $fill) {
                $hasFill = $tableCollection->where('name', DB::getTablePrefix() . $fill)->first();
                if (!$hasFill) {
                    $this->info(DB::getTablePrefix() . $fill . '表未创建');
                    continue;
                }
                $fillTableCollection = $fillTableCollection->merge([$hasFill]);
            }

            $tableCollection = $fillTableCollection;

        } elseif (count($this->generator['ignore'])) {
            $tableCollection = Collection::make($tables)->filter(function ($item, $key) {
                foreach ($this->generator['ignore'] as $ignore) {
                    if (DB::getTablePrefix() . $ignore == $item->name) {
                        return false;
                    }
                }
                return true;
            });
        }

        $path = $this->generator['path'];
        $base_path = $this->generator['path'] . '/base';
        if (!$this->files->exists($base_path)) {
            @mkdir($base_path, 0777, true);
        }
        if (!$this->files->exists($path)) {
            @mkdir($path, 0777, true);
        }

        $packages = require_once base_path('vendor/composer/autoload_psr4.php');

        $tableCollection->each(function ($item) use ($path, $packages) {
            $array = $this->replaceString($item->name);

            if ($array) {
                if ($this->files->exists($path . '/' . $array['class'] . '.php')) {
                    if (!$this->confirm("models {$array['class']} 已经存在, 请确认是否覆盖? [y|N]")) {
                        $this->warn($array['class'] . ' created defeated.');
                        return;
                    }
                }
                $file = $this->modelOut();
                $file = str_replace('{{ClassName}}', $array['class'], $file);
                $this->files->put($path . '/base/' . $array['class'] . 'Base' . '.php', $array['file']);
                if (!is_file($path . '/' . $array['class'] . '.php')) {
                    $this->files->put($path . '/' . $array['class'] . '.php', $file);
                }
                $this->info($array['class'] . ' created successfully.');

                if (array_key_exists('Doctrine\DBAL\\', $packages) && array_key_exists('Barryvdh\LaravelIdeHelper\\', $packages)) {
                    $this->call("ide-helper:models", [
                        'model' => [$array['namespace']],
                        '-W' => 'default'
                    ]);
                }
            }
        });

    }

    public function modelOut()
    {
        $file = file_get_contents(__DIR__ . '/../stubs/model_out.stub');


        return $file;
    }

    /**
     * 获取数据库表字段
     * @param $table
     * @return mixed
     * @date 2019-06-10
     * @author john_chu
     */
    public function getTableProperties($table)
    {
        $database = new Database();

        $columns = json_decode(json_encode($database->getTableColumns($table)), true);

        // 获取所有字段
        $columnsCollection = Collection::make($columns);
        // 判断创建与更新时间戳
        if ($columnsCollection->whereIn('name', $this->generator['timestamps'])->count() === 2) {
            $properties['timestamps'] = $this->generator['timestamps'];

            $columnsCollection = $columnsCollection->whereNotIn('name', $this->generator['timestamps']);

        } else {
            $properties['timestamps'] = false;
        }
        // 判断软删除时间戳
        if ($columnsCollection->where('name', $this->generator['soft_deletes'])->count() === 1) {
            $properties['softDeletes'] = $this->generator['soft_deletes'];

            $columnsCollection = $columnsCollection->whereNotIn('name', $this->generator['soft_deletes']);
        } else {
            $properties['softDeletes'] = false;
        }
        $primaryKey = $database->getTablePrimaryKey($table);
        // 判断主键
        switch (count($primaryKey)) {
            case 0:
                // 无主键可能是 关联表
                $properties['primaryKey'] = false;
                break;
            case 1:
                $properties['primaryKey'] = $primaryKey[0];

                $primaryKey = $columnsCollection->where('name', $primaryKey[0])->first();

                $properties['primaryKeyType'] = $primaryKey['type'];

                $columnsCollection = $columnsCollection->whereNotIn('name', [$primaryKey['name']]);

                break;
            default:
                // 大于两个主键 可能是关联表
                $properties['primaryKey'] = $primaryKey;
        }

        $properties['columns'] = $columnsCollection->toArray();

        return $properties;
    }

    /**
     * Replace the namespace for the given stub.
     *
     * @param string $stub
     * @param string $name
     * @return $this
     */
    protected function replaceNamespace(&$stub, $name)
    {
        $stub = str_replace(
            ['DummyNamespace', 'DummyRootNamespace', 'NamespacedDummyUserModel'],
            [$this->getNamespace($name), $this->rootNamespace(), $this->userProviderModel()],
            $stub
        );

        return $this;
    }

    /**
     * 替换模板变量
     * @param $table
     * @return array|void
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     * @date 2019-06-10
     * @author john_chu
     */
    protected function replaceString($table)
    {
        // 移除表前缀
        $tableName = str_replace(DB::getTablePrefix(), '', $table);
        // 获取驼峰class 名称
        if (!empty($this->generator['model_add_s'])) {
            $className = Character::convertUnderline($tableName); //解决goods 变good之类的
        } else {
            $className = Str::studly(Str::singular($tableName)); //官方
        }
        $properties = $this->getTableProperties($table);

        $classFile = $this->buildClass($className . 'Base');
        $this->is_base = true;
        if (is_array($properties['primaryKey'])) {
            if (count($properties['primaryKey']) == 0) {
                $this->warn($tableName . '表未设置主键, 使用默认的主键id, int型, 自增长');
                $classFile = str_replace('{{primaryKey}}', '', $classFile);
                $classFile = str_replace('{{keyType}}', '', $classFile);
                $classFile = str_replace('{{incrementing}}', '', $classFile);
            } else {
                $this->warn($tableName . '表设置主键大于1个, 可能是关联表, 未创建model(下个版本实现)');
                return;
            }
        } elseif ($properties['primaryKey'] === false) {
            $classFile = str_replace('{{primaryKey}}', '', $classFile);
            $classFile = str_replace('{{keyType}}', '', $classFile);
            $classFile = str_replace('{{incrementing}}', '', $classFile);
        } else {
            if (stripos($properties['primaryKeyType'], 'int') !== false) {
                $classFile = str_replace('{{keyType}}', '', $classFile);

                if ($properties['primaryKey'] == 'id') {
                    $classFile = str_replace('{{primaryKey}}', '', $classFile);
                } else {

                    $classFile = str_replace('{{primaryKey}}', 'protected $primaryKey = \'' . $properties['primaryKey'] . '\';', $classFile);
                }
                // int默认自动增长
                $classFile = str_replace('{{incrementing}}', '', $classFile);
            } else {

                $classFile = str_replace('{{primaryKey}}', 'protected $primaryKey =\'' . $properties['primaryKey'] . '\';', $classFile);
                $classFile = str_replace('{{keyType}}', 'protected $keyType = \'string\';', $classFile);
                $classFile = str_replace('{{incrementing}}', 'public $incrementing = false;', $classFile);
            }
        }
        if (!$this->is_base) {
            $classFile = str_replace('{{namespace}}', $this->generator['namespace'], $classFile);
        } else {
            $classFile = str_replace('{{namespace}}', $this->generator['namespace'] . '\base', $classFile);
        }


        $classFile = str_replace('{{extends}}', $this->generator['extends'], $classFile);

        $classFile = str_replace('{{shortNameExtends}}', $this->generator['extends_name'], $classFile);

        // 判断是否存在软删除
        if ($properties['softDeletes']) {
            $classFile = str_replace('{{softDeletes}}', 'use SoftDeletes;', $classFile);
            $classFile = str_replace('{{useSoftDeletes}}', 'use Illuminate\Database\Eloquent\SoftDeletes;', $classFile);
        } else {
            $classFile = str_replace('{{useSoftDeletes}}', '', $classFile);
            $classFile = str_replace('{{softDeletes}}', '', $classFile);
        }

        $classFile = str_replace('{{table}}', 'protected $table =\'' . $tableName . '\';', $classFile);

        // 判断时间戳
        if (!$properties['timestamps']) {
            $classFile = str_replace('{{timestamps}}', 'public $timestamps = false;', $classFile);
            $classFile = str_replace('{{created_at}}', '', $classFile);
            $classFile = str_replace('{{updated_at}}', '', $classFile);

        } elseif (is_array($properties['timestamps']) && count($properties['timestamps']) == 2) {
            $classFile = str_replace('{{timestamps}}', '', $classFile);

            if ($properties['timestamps']['created_at'] != 'created_at') {
                $classFile = str_replace('{{created_at}}', "const CREATED_AT = '{$properties['timestamps']['created_at']}';", $classFile);
            } else {
                $classFile = str_replace('{{created_at}}', '', $classFile);
            }
            if ($properties['timestamps']['updated_at'] != 'updated_at') {
                $classFile = str_replace('{{updated_at}}', "const UPDATED_AT = '{$properties['timestamps']['updated_at']}';", $classFile);
            } else {
                $classFile = str_replace('{{updated_at}}', '', $classFile);
            }

        } else {
            $classFile = str_replace('{{timestamps}}', 'public $timestamps = false;', $classFile);
            $classFile = str_replace('{{created_at}}', '', $classFile);
            $classFile = str_replace('{{updated_at}}', '', $classFile);
        }

        $classFile = str_replace('{{fillable}}', 'protected $fillable = ' . VariableConversion::arrayKey($properties['columns'], 'name') . ';', $classFile);

        $classFile = str_replace(PHP_EOL . '    ' . PHP_EOL, '', $classFile);
        $classFile = str_replace('{{date}}', date('Y-m-d'), $classFile);

        return ['file' => $classFile, 'class' => $className, 'namespace' => $this->generator['namespace'] . '\\' . $className];
    }

    protected function getStub()
    {
        return __DIR__ . '/../stubs/model.stub';
    }

    protected function getArguments()
    {
        return [
            ['name', InputArgument::OPTIONAL, 'The name of the class'],
        ];
    }

    protected function getOptions()
    {
        return [
        ];
    }
}
