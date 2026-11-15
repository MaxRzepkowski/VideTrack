{{-- Any 5xx without a view of its own (502, 504, …): same as 4xx. --}}
@extends('errors::minimal')

@section('title', __(\Symfony\Component\HttpFoundation\Response::$statusTexts[$exception->getStatusCode()] ?? 'Server Error'))
@section('code', (string) $exception->getStatusCode())
@section('message', __(\Symfony\Component\HttpFoundation\Response::$statusTexts[$exception->getStatusCode()] ?? 'Server Error'))
