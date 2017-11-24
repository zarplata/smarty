<?php
/**
 * Плагин для Smarty_Compiler
 *
 * Плагин позволяет встраивать контент подключаемых шаблонов
 * в основной, что уменьшает время на подключение скомпилированных шаблонов
 * при рендере html
 *
 * Как пользоваться:
 * Сперва подключаем плагин в приложении:
 *
 * <code>
 * $smarty->load_filter('pre','inline');
 * </code>
 *
 * Теперь в шаблонах доступен тег {inline},
 * который по синтаксису аналогичен {include}.
 *
 * Пример:
 * {inline file="foo.tpl"}
 *
 * Так же, в подключаемый шаблон могут быть переданы переменные
 * {inline file="foo.tpl" var1=foo var2=bar}
 *
 * ВАЖНО!
 * - Имя шаблона должно быть объявлено явно. Использование переменных в качестве пути до шаблона не допускается
 * - В качестве значений переменных, передаваемых шаблону можно использовать только скалярные переменные или массивы
 *
 * @param string $source
 * @param Smarty_Compiler $compiler
 * @return mixed
 */
function smarty_prefilter_inline($source, &$compiler)
{
    if (empty($compiler->_inline_depth)) {
        $compiler->_inline_depth = 0;
        /* Требуется для функции обработчика */
        $GLOBALS['__compiler'] =& $compiler;
    }

    $regexpSep = '#';
    $compiler->_inline_depth++;
    $source = preg_replace_callback(
        $regexpSep . preg_quote($compiler->left_delimiter, $regexpSep) .
        'inline(.*)'
        . preg_quote($compiler->right_delimiter, $regexpSep) . $regexpSep . 'Us',
        'inline_callback',
        $source
    );

    if (--$compiler->_inline_depth == 0) {
        /* Чистим $GLOBALS */
        unset($GLOBALS['__compiler']);
    }

    return $source;
}

/**
 * Функция для обработки тега {inline}
 *
 * @param string $match
 * @return bool|string
 */
function inline_callback($match)
{
    global $__compiler;

    $phpOpenTag = $__compiler->left_delimiter . 'php' . $__compiler->right_delimiter;
    $phpCloseTag = $__compiler->left_delimiter. '/php' . $__compiler->right_delimiter;

    $sourceContent = '';

    /* Получаем аргуметы, с которыми вызвали {inline} */
    $args = $__compiler->_parse_attrs($match[1]);

    /* Проверяем наличие обязательного аргумента "file" */
    if (!isset($args['file'])) {
        $this->syntax_error('[inline] missing file-parameter');
        return false;
    }
    $resourceName = $__compiler->_dequote($args['file']);
    unset($args['file']);

    /* Отрабатываем "assign" */
    $assign = null;
    if (isset($args['assign'])) {
        $assign = $args['assign'];
        unset($args['assign']);
    }

    /* Собираем все остальные аргументы */
    if (!empty($args)) {
        $sourceContent .= $phpOpenTag;
        $sourceContent .= '$this->assign([';

        foreach ($args as $argName => $argValue) {
            $value = is_array($argValue) ? var_export($argValue, true) : $argValue;
            $sourceContent .= "'$argName' => $value,";
        }
        $sourceContent .= ']);';
        $sourceContent .= $phpCloseTag;
    }

    /* Компилируем встраиваемый шаблон */
    $params = array('resource_name' => $resourceName);
    if ($__compiler->_fetch_resource_info($params)) {
        /* Рекурсивный вызов на случай если внутри есть ещё {inline} */
        $sourceContent .= smarty_prefilter_inline($params['source_content'], $__compiler);
        /* Если результат нужно передать в переменную из атрибута "assign", добавляем буферизацию */
        if (!is_null($assign)) {
            $sourceContent = $phpOpenTag . 'ob_start();' . $phpCloseTag
                . $sourceContent
                . $phpOpenTag
                . '$this->assign(' . $assign . ', ob_get_clean());'
                . $phpCloseTag;
        }
    }
    return $sourceContent;
}
