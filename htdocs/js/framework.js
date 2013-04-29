// console everywhere!
window.console = window.console || { log: function(){}, error: function(){} };
var common = window.common || {}; common.models = {};
// framework
!function($, B) { 
  
  'use strict';
  
  // jQuery-wrapped window and doc references
  window.$W = $(window);
  window.$D = $(document);
  
  // process any queued "ready" functions
  $(function() {
    var q = window.ready || [];
    ready = {
      push: function(fx) {
        fx($);
      }
    }
    $.each(q, function(i, fx) {
      fx($);
    });
  });

  $.api = function(path, data, type, onSuccess) {
    var onError = function() {

    };

    // polymorhpism...
    if ($.isFunction(data)) {
      onSuccess = data;
      data = {};
      type = 'GET';
    } else if ($.isFunction(type)) {
      onSuccess = type;
      type = 'GET';
    } else if ($.isPlainObject(path)) {
      onSuccess = path.success;
      onError = ( $.isFunction(path.error) && path.error ) || onError;
      data = path.data || {};
      type = path.type || 'GET'; 
      path = path.path;
    } else if (!data && !type && !onSuccess) {
      onSuccess = null;
      type = 'GET';
      data = {};
    }

    // prefix path with '/'
    if (path.substring(0, 1) !== '/') {
      path = '/' + path;
    }

    // translate type into request method
    var method = type.toUpperCase();
    if (type === 'PUT' || type === 'DELETE') {
      method = 'POST';
    }

    $.ajax({
      'type': method,
      'url': '/api' + path,
      'data': data && method === 'POST' && $.isPlainObject(data) ? JSON.stringify(data) : data,
      'processData': method !== 'POST' && $.isPlainObject(data),
      'beforeSend': function(xhr) {
        if (method !== type) {
          xhr.setRequestHeader('X-HTTP-Method-Override', type);
        }
        if (data && method === 'POST' && $.isPlainObject(data)) {
          xhr.setRequestHeader('Content-Type', 'application/json');
        }
        /*
        if (method === 'POST') {
          xhr.setRequestHeader('X-CSRF', CSRF);
        }
        */
      },
      'dataType': 'json',
      'success': function(response) {
        if (response.error) {
          onError(response.error);
        } else {
          if ($.isFunction(onSuccess)) {
            onSuccess(response);
          }
        }
      },
      'error': function(jqXHR, textStatus, errorThrown) {
        // is response body JSON? treat as error...
        var error = $.api._parseError(jqXHR);
        if (error) {
          onError(error);
        } else {
          console.error(jqXHR, textStatus, errorThrown);
        }
      }
    });
  };

  $.api._parseError = function(xhr) {
    if ( xhr.responseText.indexOf('{"error":') === 0 ) {
      var response = eval('('+xhr.responseText+')');
      return response.error;
    }
    return false;
  }

  Backbone.emulateHTTP = true;

  common.models.User = _.extend(B.Model.extend({
    url: function() {
      return '/api/user' + ( this.get('id') ? '/' + this.get('id') : '' );
    }
  }), {
    login: function(email_address, password, success, error) {
      return $.api({
        path: '/user/login', 
        data: { 'email_address': email_address, 'password': password },
        type: 'POST',
        success: callback,
        error: error
      });
    },
    register: function(email_address, password) {
      return $.api({
        path: '/user/register', 
        data: { 'email_address': email_address, 'password': password },
        type: 'POST',
        success: callback,
        error: error
      });
    }
  });

}(jQuery, Backbone);