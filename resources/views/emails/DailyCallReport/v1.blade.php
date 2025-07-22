<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $subject }}</title>
    <style>
    </style>
</head>
<body>
<div class="content">


     <table width="100%" style="background:#fff;border-left:1px solid #e4e4e4;border-right:1px solid #e4e4e4;border-bottom:1px solid #e4e4e4;font-family:Arial,Helvetica,sans-serif" border="0" cellpadding="0" cellspacing="0" align="center">
        <tbody>
        <tr>
            <td style="border-top:solid 4px #dddddd;line-height:1">
                <table width="100%" border="0" cellspacing="0" cellpadding="0" style="border-bottom:1px solid #e4e4e4;border-top:none">
                    <tbody>
                    <tr>
                        <td align="left" valign="top">
                            <div style="padding: 7px 12px 8px 8px;">
                                <img src="{{$data["logo"]}}" valign="middle" style="height: 54px;">
                            </div>
                        </td>
                    </tr>
                    </tbody>
                </table>
            </td>
        </tr>
    </tbody>
</table>


    <table width="100%" style="background:#fff;border-left:1px solid #e4e4e4;border-right:1px solid #e4e4e4;border-bottom:1px solid #e4e4e4;font-family:Arial,Helvetica,sans-serif" border="0" cellpadding="0" cellspacing="0" align="center">
        <tbody>
        <tr>
            <td style="width: 50%;">
                <p style="padding: 8px;
    border: 1px solid #ddd;
    padding-top: 12px;
    padding-bottom: 12px;
    text-align: left;
    background-color: #444444;
    color: white;">
                    <strong>Total Inbound/OutBound Calls</strong>
                </p>
                <div style="clear:both;padding:11px 7px 12px;margin-bottom:8px;border:1px solid #f5f5f5;border-radius:3px;background:#fff">
                    <table style="font-family:Arial, Helvetica, sans-serif;border-collapse: collapse;width: 100%;">
                        <tr>
                            <td style="border: 1px solid #ddd;padding: 8px;">Total Number Of Outbound Calls Made Manually</td>
                            <td style="border: 1px solid #ddd;padding: 8px;">{{$data["total_outbound_Calls_manually"]}}</td>

                           
                        </tr>
                        <tr>
                            <td style="border: 1px solid #ddd;padding: 8px;">Total Number Of Outbound Calls via Dialer</td>
                            <td style="border: 1px solid #ddd;padding: 8px;">{{$data["total_outbound_Calls_dialer"]}}</td>
                        </tr>

                         <tr>
                            <td style="border: 1px solid #ddd;padding: 8px;">Total Number Of Outbound Calls via C2C</td>
                            <td style="border: 1px solid #ddd;padding: 8px;">{{$data["total_outbound_Calls_c2c"]}}</td>
                        </tr>
                        <tr>
                            <td style="border: 1px solid #ddd;padding: 8px;">Total Number Of Outbound Calls</td>
                            <td style="border: 1px solid #ddd;padding: 8px;">{{$data["total_outbound_Calls"]}}</td>
                        </tr>

                        <tr>
                             <td style="border: 1px solid #ddd;padding: 8px;">Inbound Calls</td>
                            <td style="border: 1px solid #ddd;padding: 8px;">{{$data["total_inbound_Calls"]}}</td>
                        </tr>
                    </table>
                </div>
            </td>

            <td style="width:50%">
                <p style="padding: 8px;
    border: 1px solid #ddd;
    padding-top: 12px;
    padding-bottom: 12px;
    text-align: left;
    background-color: #444444;
    color: white;">
                    <strong>Total SMS/FAX Report Details</strong>
                </p>
                <div style="clear:both;padding:11px 7px 12px;margin-bottom:44px;border:1px solid #f5f5f5;border-radius:3px;background:#fff">
                    <table style="font-family:Arial, Helvetica, sans-serif;border-collapse: collapse;width: 100%;">
                        <tr>
                            <td style="border: 1px solid #ddd;padding: 8px;">Total Number Of SMS Received</td>
                            <td style="border: 1px solid #ddd;padding: 8px;">{{$data["total_sms_receive"]}}</td>
                        </tr>
                        <tr>
                            <td style="border: 1px solid #ddd;padding: 8px;">Total Number Of SMS Sent</td>
                            <td style="border: 1px solid #ddd;padding: 8px;">{{$data["total_sms_send"]}}</td>
                        </tr>

                        <tr>
                            <td style="border: 1px solid #ddd;padding: 8px;">Total Number Of SMS Received</td>
                            <td style="border: 1px solid #ddd;padding: 8px;">{{$data["total_sms_receive"]}}</td>
                        </tr>
                        <tr>
                            <td style="border: 1px solid #ddd;padding: 8px;">Total Number Of SMS Sent</td>
                            <td style="border: 1px solid #ddd;padding: 8px;">{{$data["total_sms_send"]}}</td>
                        </tr>
                    </table>
                </div>
            </td>
        </tr>

    </tbody>
</table>



  <table width="100%" style="background:#fff;border-left:1px solid #e4e4e4;border-right:1px solid #e4e4e4;border-bottom:1px solid #e4e4e4;font-family:Arial,Helvetica,sans-serif" border="0" cellpadding="0" cellspacing="0" align="center">
        <tbody>

            <tr>
                <td style="width: 100%;">
                    <p style="padding: 8px;border: 1px solid #ddd;padding-top: 12px;padding-bottom: 12px;text-align: left;background-color: white;color: black;">
                        <strong>SMS AI Report</strong>
                    </p>

                    <div class="box-body">
                        <table width="100%" style="background:#fff;border-left:1px solid #e4e4e4;border-right:1px solid #e4e4e4;border-bottom:1px solid #e4e4e4;font-family:Arial,Helvetica,sans-serif" border="0" cellpadding="0" cellspacing="0" align="center">
                            
                          

                                                        
                            
                                <tbody>
                       
                            <tr>
                                <th style="padding: 8px;border: 1px solid #ddd;padding-top: 12px;padding-bottom: 12px;text-align: left;background-color: #444444;color: white;">Total SMS Send </th>
                                <th style="padding: 8px;border: 1px solid #ddd;padding-top: 12px;padding-bottom: 12px;text-align: left;background-color: #444444;color: white;">Total SMS Replied</th>
                              
                            </tr>


                            

                            
                                <tr>
                                    <td style="border: 1px solid #ddd;padding: 8px;">{{$data['outgoing']}}</td>
                                    <td style="border: 1px solid #ddd;padding: 8px;">{{$data['incoming']}}</td>
                                    

                                </tr>

                           
                            </tbody>
                        </table>
                    </div>
                </td>
            </tr>
        </tbody>
    </table>

@if(!empty($data["campaign"]))
   <table width="100%" style="background:#fff;border-left:1px solid #e4e4e4;border-right:1px solid #e4e4e4;border-bottom:1px solid #e4e4e4;font-family:Arial,Helvetica,sans-serif" border="0" cellpadding="0" cellspacing="0" align="center">
        <tbody>
        <tr>
            <td style="width: 100%;">
                <p style="padding: 8px;
    border: 1px solid #ddd;
    padding-top: 12px;
    padding-bottom: 12px;
    text-align: left;
    background-color: white;
    color: black;">
                    <strong>Campaign Wise Outbound Summary</strong>
                </p>
                <div class="box-body">
                        <table width="100%" style="background:#fff;border-left:1px solid #e4e4e4;border-right:1px solid #e4e4e4;border-bottom:1px solid #e4e4e4;font-family:Arial,Helvetica,sans-serif" border="0" cellpadding="0" cellspacing="0" align="center">
                            
                          

                                                        
                            
                                <tbody>

                                    @php
                            $total_campaign = 0;
                            @endphp

                          

                            @foreach($data['campaign'] as $camp)
                            @php
                            $total_campaign += $camp['calls'];
                            @endphp

                                    <tr style="padding: 8px;border: 1px solid #ddd;padding-top: 12px;padding-bottom: 12px;text-align: left;background-color: #444444;color: white;">
                                    <td style="border: 1px solid #ddd;padding: 8px;font-weight: 800;font-size: 15px;">Campaign Name : {{$camp['title']}}</td>
                                    <td style="border: 1px solid #ddd;padding: 8px;font-weight: 800;font-size: 15px;"><span class="badge bg-blue">{{$camp['calls']}}</span></td>
                                </tr>


                                

                                @foreach($camp['disposition'] as $camp_dispo)

                                @php

                                if (!empty($camp_dispo->title))
                                {
                                    $title = $camp_dispo->title;
                                }
                                                        
                               else
                                if($camp_dispo->disposition_id == '101')
                                {
                                    $title = "No Agent Available";
                                }
                            
                                else
                                 if($camp_dispo->disposition_id == '102')
                                {
                                    $title =  "AMD Hangup";
                                }
                            
                                else
                                 if($camp_dispo->disposition_id == '103')
                                {
                                    $title = "Voice Drop";
                                    
                                }

                                else
                                if($camp_dispo->disposition_id== '104')
                                {
                                    $title = "Cancelled By User";
                                }

                                else
                                if($camp_dispo->disposition_id == '105')
                                {
                                    $title = "Channel Unavailable";
                                }
                                else
                                if($camp_dispo->disposition_id == '106')
                                {
                                    $title = "Congestion";
                                }
                                else
                                if($camp_dispo->disposition_id == '107')
                                {
                                    $title = "Line Busy";
                                }

                                else
                                if($camp_dispo->disposition_id == '108')
                                {
                                    $title = "CRM CALL";
                                }
                                else 
                                {
                                    $title =  'No Disposition';
                                }

                                @endphp
                                
                                <tr>
                                    <td style="border: 1px solid #ddd;padding: 8px;">{{$title}}</td>
                                    <td style="border: 1px solid #ddd;padding: 8px;"><span class="badge bg-blue">{{$camp_dispo->disposition}}</span></td>

                                </tr>

                               
                                @endforeach

                                @endforeach
                                                        </tbody></table>
                    </div>
            </td>

           
        </tr>

    </tbody>
</table>

@endif

 @if(!empty($data["agent"]))
 <table width="100%" style="background:#fff;border-left:1px solid #e4e4e4;border-right:1px solid #e4e4e4;border-bottom:1px solid #e4e4e4;font-family:Arial,Helvetica,sans-serif" border="0" cellpadding="0" cellspacing="0" align="center">
        <tbody>
       
        
      

        @if(!empty($data["agent"]))
        <tr>
            <td style="width: 100%;">
                <p style="padding: 8px;
    border: 1px solid #ddd;
    padding-top: 12px;
    padding-bottom: 12px;
    text-align: left;
    background-color: white;
    color: black;">
                    <strong>Agent Wise Summary</strong>
                </p>
                <div class="box-body">
                        <table width="100%" style="background:#fff;border-left:1px solid #e4e4e4;border-right:1px solid #e4e4e4;border-bottom:1px solid #e4e4e4;font-family:Arial,Helvetica,sans-serif" border="0" cellpadding="0" cellspacing="0" align="center">
                            
                          

                                                        
                            
                                <tbody>
                       
                            <tr>
                                <th style="padding: 8px;border: 1px solid #ddd;padding-top: 12px;padding-bottom: 12px;text-align: left;background-color: #444444;color: white;">Agent Name</th>
                                <th style="padding: 8px;border: 1px solid #ddd;padding-top: 12px;padding-bottom: 12px;text-align: left;background-color: #444444;color: white;">Extension</th>
                                <th style="padding: 8px;border: 1px solid #ddd;padding-top: 12px;padding-bottom: 12px;text-align: left;background-color: #444444;color: white;">Total Calls</th>
                                <th style="padding: 8px;border: 1px solid #ddd;padding-top: 12px;padding-bottom: 12px;text-align: left;background-color: #444444;color: white;">Oubound Calls</th>

                                <th style="padding: 8px;border: 1px solid #ddd;padding-top: 12px;padding-bottom: 12px;text-align: left;background-color: #444444;color: white;">C2C Calls</th>

                                <th style="padding: 8px;border: 1px solid #ddd;padding-top: 12px;padding-bottom: 12px;text-align: left;background-color: #444444;color: white;">Inbound Calls</th>
                                <th style="padding: 8px;border: 1px solid #ddd;padding-top: 12px;padding-bottom: 12px;text-align: left;background-color: #444444;color: white;">Total Call Time</th>
                                <th style="padding: 8px;border: 1px solid #ddd;padding-top: 12px;padding-bottom: 12px;text-align: left;background-color: #444444;color: white;">Average Handle time</th>
                                <th style="padding: 8px;border: 1px solid #ddd;padding-top: 12px;padding-bottom: 12px;text-align: left;background-color: #444444;color: white;">SMS Sent</th>
                                <th style="padding: 8px;border: 1px solid #ddd;padding-top: 12px;padding-bottom: 12px;text-align: left;background-color: #444444;color: white;">SMS Received</th>
                            </tr>

                            @php
                            $total_totalcalls = 0;
                            $total_outbound = 0;
                            $total_c2c = 0;

                            $total_inbound = 0;
                            $total_outgoing = 0;
                            $total_incoming = 0;

                            $total_agent_duration = 0;
                            $total_agent_aht = 0;





                            @endphp
                            @foreach($data["agent"] as $agentcall)

                            @php
                                $total_totalcalls +=$agentcall['totalcalls'];
                                $total_outbound +=$agentcall['outbound'];
                                $total_c2c +=$agentcall['c2c'];

                                $total_inbound +=$agentcall['inbound'];
                                $total_outgoing +=$agentcall['outgoing'];
                                $total_incoming +=$agentcall['incoming'];

                                $total_agent_duration +=$agentcall['duration'];
                                $total_agent_aht +=$agentcall['aht'];




                            @endphp


                                <tr>
                                    <td style="border: 1px solid #ddd;padding: 8px;">{{$agentcall["agentName"]}}</td>
                                    <td style="border: 1px solid #ddd;padding: 8px;">{{$agentcall["extension"]}}</td>
                                    <td style="border: 1px solid #ddd;padding: 8px;">{{$agentcall["totalcalls"]}}</td>
                                    <td style="border: 1px solid #ddd;padding: 8px;">{{$agentcall["outbound"]}}</td>
                                    <td style="border: 1px solid #ddd;padding: 8px;">{{$agentcall["c2c"]}}</td>

                                    <td style="border: 1px solid #ddd;padding: 8px;">{{$agentcall["inbound"]}}</td>
                                    <td style="border: 1px solid #ddd;padding: 8px;">{{hhmmss($agentcall["duration"])}}</td>
                                    <td style="border: 1px solid #ddd;padding: 8px;">{{hhmmss($agentcall["aht"])}}</td>
                                    <td style="border: 1px solid #ddd;padding: 8px;">{{$agentcall["outgoing"]}}</td>
                                    <td style="border: 1px solid #ddd;padding: 8px;">{{$agentcall["incoming"]}}</td>

                                </tr>

                            @endforeach

                            <tr>
                                    <th style="padding: 8px;border: 1px solid #ddd;padding-top: 12px;padding-bottom: 12px;text-align: left;background-color: #444444;color: white;">Total</th>
                                    <th style="padding: 8px;border: 1px solid #ddd;padding-top: 12px;padding-bottom: 12px;text-align: left;background-color: #444444;color: white;"></th>
                                    <th style="padding: 8px;border: 1px solid #ddd;padding-top: 12px;padding-bottom: 12px;text-align: left;background-color: #444444;color: white;">{{$total_totalcalls}}</th>
                                    <th style="padding: 8px;border: 1px solid #ddd;padding-top: 12px;padding-bottom: 12px;text-align: left;background-color: #444444;color: white;">{{$total_outbound}}</th>
                                    <th style="padding: 8px;border: 1px solid #ddd;padding-top: 12px;padding-bottom: 12px;text-align: left;background-color: #444444;color: white;">{{$total_c2c}}</th>
                                    <th style="padding: 8px;border: 1px solid #ddd;padding-top: 12px;padding-bottom: 12px;text-align: left;background-color: #444444;color: white;">{{$total_inbound}}</th>
                                    <th style="padding: 8px;border: 1px solid #ddd;padding-top: 12px;padding-bottom: 12px;text-align: left;background-color: #444444;color: white;">{{hhmmss($total_agent_duration)}}</th>
                                    <th style="padding: 8px;border: 1px solid #ddd;padding-top: 12px;padding-bottom: 12px;text-align: left;background-color: #444444;color: white;">{{hhmmss($total_agent_aht)}}</th>
                                    <th style="padding: 8px;border: 1px solid #ddd;padding-top: 12px;padding-bottom: 12px;text-align: left;background-color: #444444;color: white;">{{$total_outgoing}}</th>
                                    <th style="padding: 8px;border: 1px solid #ddd;padding-top: 12px;padding-bottom: 12px;text-align: left;background-color: #444444;color: white;">{{$total_incoming}}</th>

                                </tr>
                        </table>
                    </div>
                </div>
            </td>
        </tr>
        @endif

        </tbody>
    </table>

    @endif

@if(!empty($data["did"]))
    <table width="100%" style="background:#fff;border-left:1px solid #e4e4e4;border-right:1px solid #e4e4e4;border-bottom:1px solid #e4e4e4;font-family:Arial,Helvetica,sans-serif" border="0" cellpadding="0" cellspacing="0" align="center">
        <tbody>

            @if(!empty($data["did"]))
            <tr>
                <td style="width: 100%;">
                    <p style="padding: 8px;border: 1px solid #ddd;padding-top: 12px;padding-bottom: 12px;text-align: left;background-color: white;color: black;">
                        <strong>Inbound DID Wise Call Reort</strong>
                    </p>

                    <div class="box-body">
                        <table width="100%" style="background:#fff;border-left:1px solid #e4e4e4;border-right:1px solid #e4e4e4;border-bottom:1px solid #e4e4e4;font-family:Arial,Helvetica,sans-serif" border="0" cellpadding="0" cellspacing="0" align="center">
                            
                          

                                                        
                            
                                <tbody>
                       
                            <tr>
                                <th style="padding: 8px;border: 1px solid #ddd;padding-top: 12px;padding-bottom: 12px;text-align: left;background-color: #444444;color: white;">DID Number</th>
                                <th style="padding: 8px;border: 1px solid #ddd;padding-top: 12px;padding-bottom: 12px;text-align: left;background-color: #444444;color: white;">Inbound Calls</th>
                                <th style="padding: 8px;border: 1px solid #ddd;padding-top: 12px;padding-bottom: 12px;text-align: left;background-color: #444444;color: white;">Total Call Time</th>
                                <th style="padding: 8px;border: 1px solid #ddd;padding-top: 12px;padding-bottom: 12px;text-align: left;background-color: #444444;color: white;">Average Handle time</th>
                                <th style="padding: 8px;border: 1px solid #ddd;padding-top: 12px;padding-bottom: 12px;text-align: left;background-color: #444444;color: white;">SMS Sent</th>
                                <th style="padding: 8px;border: 1px solid #ddd;padding-top: 12px;padding-bottom: 12px;text-align: left;background-color: #444444;color: white;">SMS Received</th>
                            </tr>


                            @php
                            $total_did = 0;
                            $total_duration = 0;
                            $total_aht = 0;
                            $total_outgoing = 0;
                            $total_incoming = 0;




                            @endphp
                            @foreach($data["did"] as $list)
                            @php
                            $total_did += $list['totalcalls'];

                            $total_duration += $list["duration"];

                            $total_aht += $list["aht"];
                            $total_outgoing += $list["outgoing"];
                            $total_incoming += $list["incoming"];




                            @endphp


                            
                                <tr>
                                    <td style="border: 1px solid #ddd;padding: 8px;">{{$list["cli"]}}</td>
                                   <!--  <td style="border: 1px solid #ddd;padding: 8px;">{{$list["totalcalls"]}}</td> -->
                                    <td style="border: 1px solid #ddd;padding: 8px;">{{$list["inbound"]}}</td>
                                    <td style="border: 1px solid #ddd;padding: 8px;">{{hhmmss($list["duration"])}}</td>
                                    <td style="border: 1px solid #ddd;padding: 8px;">{{hhmmss($list["aht"])}}</td>
                                    <td style="border: 1px solid #ddd;padding: 8px;">{{$list["outgoing"]}}</td>
                                    <td style="border: 1px solid #ddd;padding: 8px;">{{$list["incoming"]}}</td>

                                </tr>
                            @endforeach

                            <tr>
                                <th style="padding: 8px;border: 1px solid #ddd;padding-top: 12px;padding-bottom: 12px;text-align: left;background-color: #444444;color: white;">Total</th>
                                <td style="padding: 8px;border: 1px solid #ddd;padding-top: 12px;padding-bottom: 12px;text-align: left;background-color: #444444;color: white;">{{$total_did}}</td>
                               
                                <td style="padding: 8px;border: 1px solid #ddd;padding-top: 12px;padding-bottom: 12px;text-align: left;background-color: #444444;color: white;">{{hhmmss($total_duration)}}</td>
                                <td style="padding: 8px;border: 1px solid #ddd;padding-top: 12px;padding-bottom: 12px;text-align: left;background-color: #444444;color: white;">{{hhmmss($total_aht)}}</td>
                                <td style="padding: 8px;border: 1px solid #ddd;padding-top: 12px;padding-bottom: 12px;text-align: left;background-color: #444444;color: white;">{{$total_outgoing}}</td>
                                <td style="padding: 8px;border: 1px solid #ddd;padding-top: 12px;padding-bottom: 12px;text-align: left;background-color: #444444;color: white;">{{$total_incoming}}</td>

                            </tr>
                            </tbody>
                        </table>
                    </div>
                </td>
            </tr>
            @endif
        </tbody>
    </table>

    @endif
    <?php //echo "<pre>";print_r($data['city_wise']);die; ?>


    @if(!empty($data["city_wise"]))

    <table width="100%" style="background:#fff;border-left:1px solid #e4e4e4;border-right:1px solid #e4e4e4;border-bottom:1px solid #e4e4e4;font-family:Arial,Helvetica,sans-serif" border="0" cellpadding="0" cellspacing="0" align="center">
        <tbody>

            @if(!empty($data["city_wise"]))
            <tr>
                <td style="width: 100%;">
                    <p style="padding: 8px;border: 1px solid #ddd;padding-top: 12px;padding-bottom: 12px;text-align: left;background-color: white;color: black;">
                        <strong>State / City / Areacode Wise Summary</strong>
                    </p>

                    <div class="box-body">
                        <table width="100%" style="background:#fff;border-left:1px solid #e4e4e4;border-right:1px solid #e4e4e4;border-bottom:1px solid #e4e4e4;font-family:Arial,Helvetica,sans-serif" border="0" cellpadding="0" cellspacing="0" align="center">
                            
                          

                                                        
                            
                                <tbody>
                       
                            <tr>
                                <th style="padding: 8px;border: 1px solid #ddd;padding-top: 12px;padding-bottom: 12px;text-align: left;background-color: #444444;color: white;">State</th>
                                <th style="padding: 8px;border: 1px solid #ddd;padding-top: 12px;padding-bottom: 12px;text-align: left;background-color: #444444;color: white;">City</th>
                                <th style="padding: 8px;border: 1px solid #ddd;padding-top: 12px;padding-bottom: 12px;text-align: left;background-color: #444444;color: white;">Areacode
                                </th>
                                <th style="padding: 8px;border: 1px solid #ddd;padding-top: 12px;padding-bottom: 12px;text-align: left;background-color: #444444;color: white;">DID
                                </th>

                                <th style="padding: 8px;border: 1px solid #ddd;padding-top: 12px;padding-bottom: 12px;text-align: left;background-color: #444444;color: white;">CNAM
                                </th>
                                <th style="padding: 8px;border: 1px solid #ddd;padding-top: 12px;padding-bottom: 12px;text-align: left;background-color: #444444;color: white;">Total Calls</th>
                                
                            </tr>
                            @php
                            $total_city_wise = 0;
                            @endphp
                            @foreach($data["city_wise"] as $areacodecall)
                            @if(!empty($areacodecall["area_code"]))
                            @php
                            $total_city_wise += $areacodecall['total'];
                            @endphp
                                <tr>
                                    <td style="border: 1px solid #ddd;padding: 8px;">{{$areacodecall["state"]}}</td>
                                    <td style="border: 1px solid #ddd;padding: 8px;">{{$areacodecall["city"]}}</td>
                                    <td style="border: 1px solid #ddd;padding: 8px;">{{$areacodecall["area_code"]}}</td>
                                    <td style="border: 1px solid #ddd;padding: 8px;">{{$areacodecall['did']}}</td>
                                    <td style="border: 1px solid #ddd;padding: 8px;">{{$areacodecall['cnam']}}</td>

                                    <td style="border: 1px solid #ddd;padding: 8px;">{{$areacodecall["total"]}}</td>
                                    
                                </tr>
                                @endif
                            @endforeach

                            <tr>
                                <th style="padding: 8px;border: 1px solid #ddd;padding-top: 12px;padding-bottom: 12px;text-align: left;background-color: #444444;color: white;">Total</th>
                                <td style="padding: 8px;border: 1px solid #ddd;padding-top: 12px;padding-bottom: 12px;text-align: left;background-color: #444444;color: white;"></td>
                                <td style="padding: 8px;border: 1px solid #ddd;padding-top: 12px;padding-bottom: 12px;text-align: left;background-color: #444444;color: white;"></td>
                                <td style="padding: 8px;border: 1px solid #ddd;padding-top: 12px;padding-bottom: 12px;text-align: left;background-color: #444444;color: white;"></td>
                                <td style="padding: 8px;border: 1px solid #ddd;padding-top: 12px;padding-bottom: 12px;text-align: left;background-color: #444444;color: white;"></td>
                                <td style="padding: 8px;border: 1px solid #ddd;padding-top: 12px;padding-bottom: 12px;text-align: left;background-color: #444444;color: white;">{{$total_city_wise}}</td>

                            </tr>
                        </tbody>
                        </table>
                    </div>
                </td>
            </tr>
            @endif
        </tbody>
    </table>
    @endif



    <table width="100%" style="background:#fff;border-left:1px solid #e4e4e4;border-right:1px solid #e4e4e4;border-bottom:1px solid #e4e4e4;font-family:Arial,Helvetica,sans-serif" border="0" cellpadding="0" cellspacing="0" align="center">
        <tbody>
    
        <tr>
            <td style="border-bottom:4px solid #dddddd;border-top:1px solid #dedede;padding:0 16px">
                <p style="color:#999999;margin:0;font-size:11px;padding:6px 0;font-family:Arial,Helvetica,sans-serif">
                    © Copyright <?php echo date('Y'); ?>  {{$data["company_name"]}}.
                </p>
            </td>
        </tr>
        </tbody>
    </table>
</div>
</body>
</html>




