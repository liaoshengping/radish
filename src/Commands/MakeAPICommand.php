<?php

namespace Radish\LaravelGenerator\Commands;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Support\Str;
use Radish\LaravelGenerator\Support\Database;
use Symfony\Component\Console\Input\InputOption;

class MakeAPICommand extends GeneratorCommand
{
    /**
     * 2019年10月11日 17:24:58
     * The console command name.
     *
     * @var string
     */
    protected $name = 'radish:api';


    protected $route;
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Build the specified API controller.';

    protected $generator;

    protected $prefix;

    protected $columns;


    protected function qualifyClass($name)
    {
        $name = ltrim($name, '\\/');

        $name = str_replace('/', '\\', $name);

        return $name;
    }

    public function handle()
    {

        if ($this->option('config')) {
            $this->generator = config('generator.api_config.' . $this->option('config'));
        } else {
            $this->generator = config('generator.api_config.' . config('generator.api_default'));
        };
        if (!$this->generator) {
            $this->error('未找到配置文件，请检查config/generator.php');
            return;
        }

        $name = $this->qualifyClass($this->getNameInput());
        //如果有包含了路径，不单独拆分名字为前缀
        if (!strstr($name, '\\')) {
            $moveController = str_replace('Controller', '', $name);
            $down_str = $this->cc_format($moveController);
            $this->prefix = $this->getPrefix($down_str);
            $name = $this->prefix . '\\' . $name;
        }
        $path = $this->generator['path'] . '/' . $this->getNamespace($name);

        $controllerFileName = $path . '/' . $this->getClassName($name) . '.php';

        if ($this->files->exists($controllerFileName)) {
            if (!$this->confirm("{$name} 已经存在, 请确认是否覆盖? [y|N]")) {
                $this->warn($name . ' created defeated.');
                return;
            }
        }
        /**
         * 获取表中的所有字段
         */
        $database = new Database();

        //ss
        $mode_name = Str::studly($this->option('model'));
        if (!empty($mode_name)) {
            $database = new Database();
            $model_name = strtolower(preg_replace('/(?<=[a-z])([A-Z])/', '_$1', $mode_name));
            $this->columns = json_decode(json_encode($database->getTableColumns($model_name)), true);
        }


        if ($this->checkModelExists()) {

            $controllerClassFile = $this->buildClassFile('model');

            $controllerClassFile = $this->replaceModel($controllerClassFile, Str::studly($this->option('model')));

            $useRequestNamespace = 'Illuminate\Http\Request';
            $requestName = 'Request';

            if ($this->generator['request']) {

                $this->checkDirAndMakeDir($this->generator['request_path']);

                $requestFile = $this->buildClassFile('request');

                $requestFile = str_replace('{{requestNamespace}}', $this->generator['request_namespace'], $requestFile);
                $requestFile = str_replace('{{requestClassName}}', Str::studly($this->option('model')) . 'Request', $requestFile);
                $fileName = $this->generator['request_path'] . '/' . Str::studly($this->option('model')) . 'Request.php';

                if (!is_file($fileName)) {
                    $this->files->put($fileName, $requestFile);
                }

                $useRequestNamespace = $this->generator['request_namespace'] . '\\' . Str::studly($this->option('model')) . 'Request';
                $requestName = Str::studly($this->option('model')) . 'Request';
            }

            $controllerClassFile = str_replace('{{useRequestNamespace}}', $useRequestNamespace, $controllerClassFile);
            $controllerClassFile = str_replace('{{requestName}}', $requestName, $controllerClassFile);
            $controller = $this->nameFormatController($name);

            $url = $this->removeControllerString($controller);
            $firstModules = explode('/', $url)[0];

            $controllerClassFile = str_replace('{{Route}}', $url, $controllerClassFile);
            $controllerClassFile = str_replace('{{modules}}', $firstModules, $controllerClassFile);

        } else {
            if ($this->option("model")) {
                $modelPath = config('generator.model.path');
                $this->warn("未在{$modelPath}找到对应的model文件");
            }
            $controllerClassFile = $this->buildClassFile();
        }

        $controllerClassFile = $this->replaceNamespace($controllerClassFile, $name)
            ->replaceClass($controllerClassFile, $name);

        $controllerClassFile = $this->replaceExtends($controllerClassFile);

        $this->checkDirAndMakeDir($path);

        $this->files->put($controllerFileName, $controllerClassFile);

        $this->info($name . ' created successfully.');

        $this->buildRoute($name);

    }

    private function cc_format($name)
    {
        $temp_array = array();
        for ($i = 0; $i < strlen($name); $i++) {
            $ascii_code = ord($name[$i]);
            if ($ascii_code >= 65 && $ascii_code <= 90) {
                if ($i == 0) {
                    $temp_array[] = chr($ascii_code + 32);
                } else {
                    $temp_array[] = '_' . chr($ascii_code + 32);
                }
            } else {
                $temp_array[] = $name[$i];
            }
        }
        return implode('', $temp_array);
    }

    protected function getPrefix($name)
    {
        $exp = explode('_', $name);
        return $exp[0];
    }

    /**
     * 检查对应文件夹是否存在，并创建对应文件夹
     * @param $path
     * @date 2019-06-12
     * @author john_chu
     */
    protected function checkDirAndMakeDir($path)
    {
        if (!$this->files->exists($path)) {
            $this->files->makeDirectory($path, 0755, true);
        }
    }

    /**
     * 生成RESTful路由
     * @param $name
     * @date 2019-06-12
     * @author john_chu
     */
    protected function buildRoute($name)
    {

        $controller = $this->nameFormatController($name);

        $url = $this->removeControllerString($controller);

        $filePath = $this->relativePath($this->generator['path'] . '/' . $this->getNamespace($name) . $this->getClassName($name));

        $this->files->append($this->generator['route'], "// {$filePath}" . PHP_EOL);

//        if ($this->lumenOrLaravel() == 'laravel') {
//            $this->files->append($this->generator['route'], "Route::apiResource('{$url}', '{$controller}');" . PHP_EOL );
//            $this->info($name . ' route created successfully.');
//            return;
//        }
        //test
        $id = 'id';
        if ($this->checkModelExists()) {
            $id = lcfirst($this->getClassName(Str::studly($this->option('model'))));
        }
        $controller = $this->nameFormatToRoute($controller);
        $this->files->append($this->generator['route'], "Route::get('{$url}', '{$controller}@index');" . PHP_EOL);
        $this->files->append($this->generator['route'], "Route::post('{$url}', '{$controller}@store');" . PHP_EOL);
        $this->files->append($this->generator['route'], "Route::get('{$url}/all', '{$controller}@all');" . PHP_EOL);
        $this->files->append($this->generator['route'], "Route::get('{$url}/{{$id}}', '{$controller}@show');" . PHP_EOL);
        $this->files->append($this->generator['route'], "Route::put('{$url}/{{$id}}', '{$controller}@update');" . PHP_EOL);
        $this->files->append($this->generator['route'], "Route::delete('{$url}/{{$id}}', '{$controller}@destroy');" . PHP_EOL);
        $this->info($name . ' route created successfully.');
    }

    /**
     * 替换为项目根目录开始的相对路径
     * @param $path
     * @return mixed
     * @date 2019-06-12
     * @author john_chu
     */
    protected function relativePath($path)
    {
        return str_replace(base_path() . '/', '', $path);
    }

    protected function nameFormatController($name)
    {
        return str_replace('\\', '/', $name);
    }

    protected function nameFormatToRoute($name)
    {
        return str_replace('/', '\\', $name);
    }

    protected function removeControllerString($name)
    {
        return strtolower(str_replace('Controller', '', $name));
    }

    protected function convertUnderline($str, $ucfirst = true)
    {
        $str = ucwords(str_replace('_', ' ', $str));
        $str = str_replace(' ', '', lcfirst($str));
        return $ucfirst ? ucfirst($str) : $str;
    }

    /**
     * 判断model是否存在
     * @date 2019-06-12
     * @author john_chu <john1668@qq.com>
     */
    protected function checkModelExists()
    {
        if ($this->option("model")) {

            $modelFile = config('generator.model.path') . '/' . Str::studly($this->option('model')) . '.php';

            if ($this->files->exists($modelFile)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 判断当前是lumen还是laravel环境
     * @return string
     * @date 2019-06-11
     * @author john_chu
     */
    protected function lumenOrLaravel()
    {
        if (stripos('Lumen', app()->version()) !== false) {
            return 'lumen';
        };
        return 'laravel';
    }

    /**
     * 生成类文件
     * @param string $name
     * @return string
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     * @date 2019-06-13
     * @author john_chu
     */
    protected function buildClassFile($name = 'api')
    {
        return $this->files->get($this->getStub($name));
    }

    /**
     * 替换空间名称
     * @param $stub
     * @param $name
     * @param string $type 'namespace|requestNamespace|resourceNamespace'
     * @return $this
     * @date 2019-06-10
     * @author john_chu
     */
    protected function replaceNamespace(&$stub, $name, $type = 'namespace')
    {
        $stub = str_replace('{{namespace}}', $this->getNamespaceName($name, $type), $stub);
        return $this;
    }

    /**
     * 替换类名
     * @param string $stub
     * @param string $name
     * @return $this|string
     * @date 2019-06-10
     * @author john_chu
     */
    protected function replaceClass($stub, $name)
    {
        $stub = str_replace('{{className}}', $this->getClassName($name), $stub);
        return $stub;
    }


    protected function replaceModel($stub, $name)
    {

        if (!empty($this->columns)) {
            $replace_params = '';
            $first = false;
            foreach ($this->columns as $table_info) {
                if ($first == true) {
                    $replace_params .= PHP_EOL;
                }
                if (empty($table_info['memo'])) {
                    switch ($table_info['name']) {
                        case 'created_at':
                            $table_info['memo'] = '创建时间';
                            break;
                        case 'updated_at':
                            $table_info['memo'] = '更新时间';
                            break;
                        case 'deleted_at':
                            $table_info['memo'] = '删除时间';
                            break;
                        case 'memo':
                            $table_info['memo'] = '备注';
                            break;
                        case 'mobile':
                            $table_info['memo'] = '手机';
                            break;
                        case 'name':
                            $table_info['memo'] = '名称';
                            break;
                        case 'goods_id':
                            $table_info['memo'] = '商品id';
                            break;
                        case 'contact_name':
                            $table_info['memo'] = '联系人';
                            break;
                        case 'type':
                            $table_info['memo'] = '类型';
                            break;
                        case 'status':
                            $table_info['memo'] = '状态';
                            break;
                    }
                }
                $replace_params .= "     * @return_param {$table_info['name']} {$table_info['type']} {$table_info['memo']}";

                $first = true;
            }
            $stub = str_replace('{{returnParams}}', $replace_params, $stub);
        }

        $stub = str_replace('{{useModelNamespace}}', config('generator.model.namespace') . '\\' . $name, $stub);
        $stub = str_replace('{{modelName}}', lcfirst($this->getClassName($name)), $stub);
        $stub = str_replace('{{upperModelName}}', $this->getClassName($name), $stub);

        return $stub;
    }

    /**
     * 替换继承类
     * @param $stub
     * @return mixed
     * @date 2019-06-10
     * @author john_chu
     */
    protected function replaceExtends($stub)
    {
        $stub = str_replace('{{extends}}', $this->generator['extends'], $stub);
        $stub = str_replace('{{extendsName}}', $this->generator['extends_name'], $stub);
        return $stub;
    }

    /**
     * 获取className
     * @param $name
     * @return mixed
     * @date 2019-06-10
     * @author john_chu
     */
    protected function getClassName($name)
    {
        return str_replace($this->getNamespace($name) . '\\', '', $name);
    }

    /**
     * 获取空间名称
     * @param $name
     * @param string $type
     * @return string
     * @date 2019-06-10
     * @author john_chu
     */
    protected function getNamespaceName($name, $type = 'namespace')
    {
        if ($this->getNamespace($name)) {
            return $this->generator[$type] . '\\' . $this->getNamespace($name);
        }
        return $this->generator[$type];
    }

    protected function getStub($name = 'api')
    {
        switch ($name) {
            case 'request':
                return __DIR__ . '/../stubs/request.stub';
            case 'model':
                return __DIR__ . '/../stubs/controller.model.api.stub';
            default:
                return __DIR__ . '/../stubs/controller.api.stub';
        }
    }

    protected function getOptions()
    {
        return [
            ['model', 'm', InputOption::VALUE_OPTIONAL, 'Generate a controller for the given model.'],
            ['config', 'c', InputOption::VALUE_OPTIONAL, 'Generates the controller using the specified configuration item.'],
        ];
    }
}
