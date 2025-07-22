<table width="100%">
    <tbody>
    <tr>
        <td>
            <table cellspacing="12" cellpadding="2" bgcolor="#FFFFFF" align="center" width="600" border="0">
                <tbody>
                <tr>
                    <td style="padding:12px;border-bottom-color:rgb(75,121,147);border-bottom-width:1px;border-bottom-style:solid" bgcolor="#ffffff">
                        <table cellspacing="1" cellpadding="1" width="100%" border="1">
                            <tbody>
                                @foreach($context as $field => $value)
                                    <tr>
                                        <td> {{ $field }}</td>
                                        @if( is_array($value))
                                            <td><pre>{{ json_encode($value, JSON_PRETTY_PRINT) }}</pre></td>
                                        @else
                                            <td> {{ $value }}</td>
                                        @endif
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </td>
                </tr>
                </tbody>
            </table>
        </td>
    </tr>
    </tbody>
</table>
