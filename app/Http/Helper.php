<?php
namespace App\Http;

class Helper
{
    private static function btnIcon($status)
    {
        switch ($status) {
            case 'edit':
                $icon = 'ti-pencil';
                break;
            case 'delete':
                $icon = 'ti-trash';
                break;
            case 'reset':
                $icon = 'ti-key';
                break;
            case 'regenerate':
                $icon = 'ti-reload';
                break;
        }
        return $icon;
    }
    private static function btnColor($btn)
    {
        switch ($btn) {
            case 'edit':
                $color = 'btn-success';
                break;
            case 'delete':
                $color = 'btn-danger';
                break;
            case 'reset':
                $color = 'btn-info';
                break;
            case 'regenerate':
                $color = 'btn-warning';
                break;
        }
        return $color;
    }
    private static function btnAction($btn, $data)
    {
        switch ($btn) {
            case 'edit':
                $action = 'edit('.$data->id.')';
                break;
            case 'delete':
                $action = 'destroy('.$data->id.')';
                break;
            case 'reset':
                $action = 'resetPassword('.$data->id.')';
                break;
            case 'regenerate':
                $action = 'regenerateCert('.$data->id.')';
                break;
        }
        return $action;
    }
    public static function actionButtons($data, $buttons)
    {
        $btn = '';
        foreach ($buttons as $key => $val) {
            $btn .= '<button class="btn btn-sm '.self::btnColor($val).'" onclick="'.self::btnAction($val, $data).'" type="button"><i class="'.self::btnIcon($val).'"></i></button>';
        }
        return '<div class="btn-group">'.$btn.'</div>';
    }
}
