{{-- Any 4xx without a view of its own (405, 413, …): the status text, which
     the language files already translate. --}}
@extends('errors::minimal')

@section('title', __(\Symfony\Component\HttpFoundation\Response::$statusTexts[$exception->getStatusCode()] ?? 'Error'))
@section('code', (string) $exception->getStatusCode())
@section('message', __(\Symfony\Component\HttpFoundation\Response::$statusTexts[$exception->getStatusCode()] ?? 'Error'))
