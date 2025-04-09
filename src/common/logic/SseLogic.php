<?php

namespace Php34\WorkerOnline\common\logic;



use think\Db;

class SseLogic
{
    public function push($type,$data){

    }
    public function pull($type){
        return $this->$type();
    }
    public function set_change_or_withdraw(){

        $recharge_count = Db::name('web_recharge_order')->where(['status'=>0])->whereNotExists(
            function($query){
                $query->table('web_user')->where(['account_type'=>2])->whereRaw('web_recharge_order.userid=web_user.userid');
            })->count();

        $withdraw_count = Db::name('web_withdraw_log')->where(['status'=>0])->whereNotExists(
            function($query){
                $query->table('web_user')->where(['account_type'=>2])->whereRaw('web_withdraw_log.userid=web_user.userid');
            })->count();

        cache('rechange_count',$recharge_count);
        cache('withdraw_count',$withdraw_count);
        return ['recharge_count'=>$recharge_count,'withdraw_count'=>$withdraw_count];
    }
    public function get_change_or_withdraw(){


        $returndata['withdraw_count'] = 1;
        $returndata['recharge_count'] = 1;


        $message['code']=200;
        $message['data']=$returndata;

        return $message;
    }

    public function __call($name, $arguments){
        $message['code']=404;
        $message['data'] = ["msg"=>"no message"];
        return $message;
    }

}