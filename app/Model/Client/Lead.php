<?php
namespace App\Model\Client;
use Illuminate\Database\Eloquent\Model;
use SoftDeletes;

class Lead extends Model
{

    public $timestamps = true;
    protected $table = "crm_lead_data";
    protected $fillable = ["first_name", "last_name", "email", "phone_number", "gender", "dob", "city", "state", "country", "address", "lead_status", "assigned_to","lead_source_id","lead_type","company_name","unique_url","owner_2_signtaure_image","owner_2_signtaure_date","unique_token",'lead_parent_id',"option_12","option_13","option_14","option_15","option_16","option_17","option_18","option_19","option_20","option_21","option_22","option_23","option_24","option_25","option_26","option_27","option_28","option_29","option_30","option_31","option_32","option_33","option_34","option_35","option_36","option_37","option_38","option_39","option_40","option_41","option_42","option_43","option_44","option_45","option_46","option_47","option_48","option_49","option_50","option_51","option_52","option_53","option_54","option_55","option_56","option_57","option_58","option_59","option_60","option_61","option_62","option_63","option_64","option_65","option_66","option_67","option_68","option_69","option_70","option_71","option_72","option_73","option_74","option_75","option_76","option_77","option_78","option_79","option_80","option_81","option_82","option_83","option_84","option_85","option_86","option_87","option_88","option_89","option_90","option_91","option_92","option_93","option_94","option_95","option_96","option_97","option_98","option_99","option_100","option_721",
    "option_722",
    "option_723",
    "option_724",
    "option_725",
    "option_726",
    "option_727",
    "option_728",
    "option_729",
    "option_730",
    "option_731",
    "option_732",
    "option_733",
    "option_734",
    "option_735",
    "option_736",
    "option_737",
    "option_738",
    "option_739",
    "option_740",
    "option_741",
    "option_742",
    "option_743",
    "option_744",
    "option_745",
    "option_746",
    "option_747",
    "option_748",
    "option_749",
    "option_750",
    'is_copied',
    'copy_lead_id','opener_id','closer_id','group_id','is_deleted'


    








];
protected $dates = ['deleted_at'];
}