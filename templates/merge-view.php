<div id="merge" class="attached input" data-toggle="tooltip" data-placement="bottom" title="{MERGING_TOOLTIP}" style="display: inline-block;height: 26px;text-align:left">
    <select id="masterid" name="masterid" style="width: 250px" class="js-example-basic-single"><option>{MERGING_PLACEHOLDER}</option>{MERGING_OPTIONS}</select>
    <button id="mergebutton" type="submit" class="attached button"><i class="icon-code-fork"></i>
    </button>
</div>
<script>
    $(document).ready(function(){
        $("#masterid").select2({});
        $("#masterid").val('');
        $("#mergebutton").click(function(){
            $.ajax({
                type: 'POST',
                url: '../include/{MERGING_INSTALL_PATH}/ajax.php',
                data: ({
                    master: {MERGING_TICKET_ID},
                    ticket: $("#masterid").val(),
                    a: 'merge'
                }),
                success: function(data) {
                    window.location.href=window.location.href;
                }
            });
        });
    });
</script>
<style>
    div#merge .select2-selection--single, div#merge .select2-selection__rendered {
        height: 26px;
        border-right: none;
        border-top-right-radius: 0px;
        border-bottom-right-radius: 0px;
    }
    div#merge .select2-container{
        margin-right: -1px;
    }
</style>